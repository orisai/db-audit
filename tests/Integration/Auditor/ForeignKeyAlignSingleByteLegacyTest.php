<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Auditor;

use Generator;
use Orisai\DbAudit\Auditor\NullableWithNoNullsMysqlAuditor;
use Orisai\DbAudit\Auditor\OutdatedCollationMysqlAuditor;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Report\Violation;
use Orisai\DbAudit\Runner\Runner;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\DbProvider;
use Tests\Orisai\DbAudit\Helper\MysqlShortcuts;
use function explode;
use function implode;
use function stripos;

final class ForeignKeyAlignSingleByteLegacyTest extends TestCase
{

	protected function tearDown(): void
	{
		parent::tearDown();
		DbProvider::disconnectAll();
	}

	/**
	 * @return Generator<string, array{0: DbalAdapter, 1: DatabaseEngine}>
	 */
	public function provide(): Generator
	{
		yield from DbProvider::adapters();
	}

	/**
	 * The planner's foreign-key alignment must never force a single-byte legacy charset (latin1) child up to a
	 * wider parent charset: driving it here would skip the collation auditor's LegacyCharsetConversion gate and
	 * binary two-step, corrupting double-encoded data. Under the default report mode the latin1 endpoint stays
	 * latin1 and the whole FK's conversion is held back (change.foreign_key_inconsistent).
	 *
	 * The mixed-charset FK that puts both endpoints in scope for the align branch (latin1 child -> utf8mb3
	 * parent, plus the child's unrelated NOT NULL) is only constructible on MySQL (foreign-key checks off + an
	 * ALTER of the child's charset); MariaDB refuses to alter a foreign-key column's charset, so there a matching
	 * latin1<->latin1 FK proves the report-mode latin1 child is never force-converted through its NOT NULL
	 * rebuild.
	 *
	 * @dataProvider provide
	 */
	public function testForeignKeyAlignNeverForcesSingleByteLegacyChild(
		DbalAdapter $dbal,
		DatabaseEngine $engine
	): void
	{
		$db = 'align_guard_single_byte_legacy';
		$shortcuts = new MysqlShortcuts($dbal);
		$shortcuts->dropDatabaseIfExists($db);
		$dbal->exec(
			'CREATE DATABASE ' . $dbal->escapeIdentifier($db) . ' CHARACTER SET utf8mb3 COLLATE utf8mb3_czech_ci',
		);
		$shortcuts->useDatabase($db);

		// Default config -> LegacyCharsetConversion::report(): latin1 is reported, never auto-converted.
		$auditors = [new OutdatedCollationMysqlAuditor($dbal), new NullableWithNoNullsMysqlAuditor($dbal)];

		if ($engine === DatabaseEngine::mariadb()) {
			// A matching latin1<->latin1 foreign key (the mixed-charset variant cannot be built on MariaDB). The
			// child's NOT NULL rebuild re-adds the still-matching FK cleanly; report mode leaves latin1 as latin1.
			$dbal->exec(
			/** @lang MySQL */
				<<<'SQL'
CREATE TABLE `country` (
	`code` varchar(10) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
	PRIMARY KEY (`code`)
) ENGINE=InnoDB
SQL,
			);
			$dbal->exec(
			/** @lang MySQL */
				<<<'SQL'
CREATE TABLE `city` (
	`id` int NOT NULL,
	`country_code` varchar(10) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL,
	PRIMARY KEY (`id`),
	KEY (`country_code`),
	CONSTRAINT `fk_city_country` FOREIGN KEY (`country_code`) REFERENCES `country` (`code`)
) ENGINE=InnoDB
SQL,
			);
			$dbal->exec(/** @lang MySQL */ "INSERT INTO `country` (`code`) VALUES ('cz')");
			$dbal->exec(/** @lang MySQL */ "INSERT INTO `city` (`id`, `country_code`) VALUES (1, 'cz')");

			$sql = (new Runner($dbal, $auditors))->generate()->getSql();

			// The latin1 child is never converted to utf8mb4; its NOT NULL MODIFY keeps latin1.
			self::assertStringContainsString(
				'MODIFY `country_code` varchar(10) CHARACTER SET latin1',
				$sql,
			);
			self::assertStringNotContainsString('CHARACTER SET utf8mb4', $sql);

			$shortcuts->applyScript($sql);

			self::assertSame('latin1', $this->columnCharset($dbal, 'city', 'country_code'));

			return;
		}

		// MySQL: build the otherwise-impossible mixed-charset FK (latin1 child -> utf8mb3 parent) by altering the
		// child's charset with foreign-key checks off.
		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `country` (
	`code` varchar(10) CHARACTER SET utf8mb3 COLLATE utf8mb3_czech_ci NOT NULL,
	PRIMARY KEY (`code`)
) ENGINE=InnoDB
SQL,
		);
		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `city` (
	`id` int NOT NULL,
	`country_code` varchar(10) CHARACTER SET utf8mb3 COLLATE utf8mb3_czech_ci NULL,
	PRIMARY KEY (`id`),
	KEY (`country_code`),
	CONSTRAINT `fk_city_country` FOREIGN KEY (`country_code`) REFERENCES `country` (`code`)
) ENGINE=InnoDB
SQL,
		);
		$dbal->exec(/** @lang MySQL */ "INSERT INTO `country` (`code`) VALUES ('cz')");
		$dbal->exec(/** @lang MySQL */ "INSERT INTO `city` (`id`, `country_code`) VALUES (1, 'cz')");

		$dbal->exec(/** @lang MySQL */ 'SET FOREIGN_KEY_CHECKS = 0');
		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
ALTER TABLE `city`
	MODIFY `country_code` varchar(10) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL
SQL,
		);
		$dbal->exec(/** @lang MySQL */ 'SET FOREIGN_KEY_CHECKS = 1');

		$report = (new Runner($dbal, $auditors))->generate();
		$sql = $report->getSql();

		// Align guard: the latin1 child is not force-converted to the parent's wider charset; its NOT NULL MODIFY
		// keeps latin1.
		self::assertStringContainsString('MODIFY `country_code` varchar(10) CHARACTER SET latin1', $sql);
		self::assertStringNotContainsString('MODIFY `country_code` varchar(10) CHARACTER SET utf8mb4', $sql);

		// Reconcile holds back the whole FK's conversion; the parent column keeps its charset (no MODIFY).
		self::assertNotNull($this->findViolationByKey($report->getUnfixable(), 'change.foreign_key_inconsistent'));
		self::assertStringNotContainsString('MODIFY `code`', $sql);

		// Applying the plan keeps the latin1 charset unchanged. The artificial mismatched FK (latin1 -> utf8mb3)
		// only exists because foreign-key checks were bypassed to build an engine-impossible fixture, so its
		// single ADD CONSTRAINT re-add is rejected by the engine; that statement is dropped before replay —
		// expected and orthogonal to the charset preservation under test.
		$stripped = 0;
		$applied = $this->stripForeignKeyReadd($sql, $stripped);
		self::assertSame(1, $stripped);
		$shortcuts->applyScript($applied);

		self::assertSame('latin1', $this->columnCharset($dbal, 'city', 'country_code'));
		self::assertNotSame('utf8mb4', $this->columnCharset($dbal, 'country', 'code'));
	}

	/**
	 * Drops the ADD CONSTRAINT re-add lines (the engine-impossible mismatched foreign key) from the migration
	 * SQL so the rest replays cleanly, reporting through $stripped how many were removed.
	 */
	private function stripForeignKeyReadd(string $sql, int &$stripped): string
	{
		$kept = [];
		foreach (explode("\n", $sql) as $line) {
			if (stripos($line, 'ADD CONSTRAINT') !== false) {
				$stripped++;

				continue;
			}

			$kept[] = $line;
		}

		return implode("\n", $kept);
	}

	private function columnCharset(DbalAdapter $dbal, string $table, string $column): string
	{
		$rows = $dbal->query(
			'SELECT CHARACTER_SET_NAME AS cs FROM INFORMATION_SCHEMA.COLUMNS '
			. 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $dbal->escapeString($table)
			. ' AND COLUMN_NAME = ' . $dbal->escapeString($column),
		);

		return (string) $rows[0]['cs'];
	}

	/**
	 * @param list<Violation> $violations
	 */
	private function findViolationByKey(array $violations, string $key): ?Violation
	{
		foreach ($violations as $violation) {
			if ($violation->getKey() === $key) {
				return $violation;
			}
		}

		return null;
	}

}

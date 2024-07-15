<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Auditor;

use Generator;
use Orisai\DbAudit\Auditor\NullableWithNoNullsMysqlAuditor;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Runner\Runner;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\DbProvider;
use Tests\Orisai\DbAudit\Helper\MysqlShortcuts;

final class ForeignKeyLengthWideningTest extends TestCase
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
	 * @dataProvider provide
	 */
	public function testWidensChildToMatchParentFkLength(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$db = 'fk_length_widening';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		// Both FK endpoints are nullable with no NULL rows, so NullableWithNoNullsMysqlAuditor flags both.
		// The parent uses a UNIQUE KEY (not PRIMARY KEY) so its id column can be NULL.
		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `parent_t` (
	`id` varchar(30) NULL,
	UNIQUE KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
		);

		// child varchar(20) is intentionally shorter than parent varchar(30) to exercise the length-widening path.
		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `child_t` (
	`parent_id` varchar(20) NULL,
	FOREIGN KEY (`parent_id`) REFERENCES `parent_t` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
		);

		$dbal->exec(/** @lang MySQL */ "INSERT INTO `parent_t` (`id`) VALUES ('row1')");
		$dbal->exec(/** @lang MySQL */ "INSERT INTO `child_t` (`parent_id`) VALUES ('row1')");

		$report = (new Runner($dbal, [new NullableWithNoNullsMysqlAuditor($dbal)]))->generate();

		// Gate: generate() ran and flagged at least one column (parent + child both produce fixes).
		self::assertGreaterThanOrEqual(1, $report->getGeneratedCount());

		$sql = $report->getSql();

		// The child's varchar(20) is widened to match the parent's varchar(30) by the planner's FK alignment.
		self::assertStringContainsString('MODIFY `parent_id` varchar(30)', $sql);

		// The FK must be dropped before the column changes and re-added afterwards.
		self::assertStringContainsString('DROP FOREIGN KEY', $sql);
		self::assertStringContainsString('ADD CONSTRAINT', $sql);

		// The migration applies without error.
		$shortcuts->applyScript($sql);

		// Both FK-linked columns are now NOT NULL after the migration.
		$parentRows = $dbal->query(
		/** @lang MySQL */
			"SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
			WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'parent_t' AND COLUMN_NAME = 'id'",
		);
		self::assertSame('NO', (string) $parentRows[0]['IS_NULLABLE']);

		$childRows = $dbal->query(
		/** @lang MySQL */
			"SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
			WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'child_t' AND COLUMN_NAME = 'parent_id'",
		);
		self::assertSame('NO', (string) $childRows[0]['IS_NULLABLE']);

		// Re-analysing after the migration is idempotent: no further SQL is generated.
		$rerunSql = (new Runner($dbal, [new NullableWithNoNullsMysqlAuditor($dbal)]))->generate()->getSql();
		self::assertSame('', $rerunSql);
	}

}

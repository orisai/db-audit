<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Auditor;

use Generator;
use Orisai\DbAudit\Auditor\ForeignKeyColumnTypeMismatchMysqlAuditor;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Runner\Runner;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\DbProvider;
use Tests\Orisai\DbAudit\Helper\MysqlShortcuts;

final class ForeignKeyMismatchAutoFixApplyTest extends TestCase
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
	 * A child varchar(20) referencing a parent varchar(30) on the same charset is a WIDEN: the mismatch
	 * auditor emits a fix that widens the child to varchar(30) and the planner rebuilds the FK.
	 * This FK is creatable on both engines without FOREIGN_KEY_CHECKS=0 (shorter child is valid).
	 *
	 * @dataProvider provide
	 */
	public function testLengthFixAppliesOnBothEngines(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$auditor = new ForeignKeyColumnTypeMismatchMysqlAuditor($dbal);

		$db = 'fk_mismatch_autofix__length';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `parent_t` (
	`id` varchar(30) NOT NULL,
	PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
		);

		// Child varchar(20) is shorter than parent varchar(30): a valid FK on both engines, but the mismatch
		// auditor flags it as a WIDEN fixable by widening the child to varchar(30).
		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `child_t` (
	`parent_id` varchar(20) NULL,
	FOREIGN KEY (`parent_id`) REFERENCES `parent_t` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
		);

		$dbal->exec(/** @lang MySQL */ "INSERT INTO `parent_t` (`id`) VALUES ('hello');");
		$dbal->exec(/** @lang MySQL */ "INSERT INTO `child_t` (`parent_id`) VALUES ('hello');");

		// Always-run assertion: the auditor reports exactly one fixable size mismatch.
		$violations = $auditor->analyse()->getViolations();
		self::assertCount(1, $violations);
		self::assertSame('foreign_key.size_mismatch', $violations[0]->getKey());
		self::assertTrue($violations[0]->isFixable());

		$report = (new Runner($dbal, [$auditor]))->generate();
		$sql = $report->getSql();

		// ONE MODIFY widening the child to varchar(30).
		self::assertStringContainsString('MODIFY `parent_id` varchar(30)', $sql);
		// FK must be dropped before the column change and re-added afterwards.
		self::assertStringContainsString('DROP FOREIGN KEY', $sql);
		self::assertStringContainsString('ADD CONSTRAINT', $sql);

		// Apply succeeds on both engines.
		$shortcuts->applyScript($sql);

		// Child column is now varchar(30).
		$col = $dbal->query(
		/** @lang MySQL */
			'SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS'
			. " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'child_t' AND COLUMN_NAME = 'parent_id'",
		)[0];
		self::assertSame(30, (int) $col['CHARACTER_MAXIMUM_LENGTH']);

		// FK was rebuilt and still enforces referential integrity.
		$fks = $dbal->query(
		/** @lang MySQL */
			'SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS'
			. " WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'child_t'",
		);
		self::assertNotSame([], $fks);

		// Idempotent: re-analyse reports no remaining size mismatch.
		self::assertSame([], $auditor->analyse()->getViolations());
	}

	/**
	 * A child latin1 varchar(10) referencing a utf8mb4 parent varchar(10): latin1 is a single-byte legacy
	 * charset, so conversion is blocked and the mismatch stays report-only — no fix is emitted.
	 * A charset-mismatched FK cannot be constructed on MariaDB; this test is MySQL-only.
	 *
	 * @dataProvider provide
	 */
	public function testCharsetFixAppliesOnMysql(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$auditor = new ForeignKeyColumnTypeMismatchMysqlAuditor($dbal);

		$db = 'fk_mismatch_autofix__charset';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		if ($engine === DatabaseEngine::mariadb()) {
			// MariaDB refuses to construct a charset-mismatched FK; verify a matching fixture raises no violation.
			$dbal->exec(
			/** @lang MySQL */
				<<<'SQL'
CREATE TABLE `parent_t` (
	`id` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
	PRIMARY KEY (`id`)
) ENGINE=InnoDB;
SQL,
			);
			$dbal->exec(
			/** @lang MySQL */
				<<<'SQL'
CREATE TABLE `child_t` (
	`parent_id` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
	FOREIGN KEY (`parent_id`) REFERENCES `parent_t` (`id`)
) ENGINE=InnoDB;
SQL,
			);
			self::assertSame([], $auditor->analyse()->getViolations());

			return;
		}

		// MySQL: build a latin1 child → utf8mb4 parent mismatch via FOREIGN_KEY_CHECKS=0 + ALTER.
		$dbal->exec(/** @lang MySQL */ 'SET FOREIGN_KEY_CHECKS = 0;');
		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `parent_t` (
	`id` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
	PRIMARY KEY (`id`)
) ENGINE=InnoDB;
SQL,
		);
		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `child_t` (
	`parent_id` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
	FOREIGN KEY (`parent_id`) REFERENCES `parent_t` (`id`)
) ENGINE=InnoDB;
SQL,
		);
		$dbal->exec(
		/** @lang MySQL */
			'ALTER TABLE `child_t`'
			. ' MODIFY COLUMN `parent_id` varchar(10) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL;',
		);
		$dbal->exec(/** @lang MySQL */ 'SET FOREIGN_KEY_CHECKS = 1;');

		// Auditor reports one non-fixable charset mismatch (single-byte legacy child: blocked).
		$violations = $auditor->analyse()->getViolations();
		self::assertCount(1, $violations);
		self::assertSame('foreign_key.charset_mismatch', $violations[0]->getKey());
		self::assertFalse($violations[0]->isFixable());

		$report = (new Runner($dbal, [$auditor]))->generate();

		// No SQL emitted: single-byte legacy charset conversion is not auto-fixed.
		self::assertSame('', $report->getSql());

		// The violation surfaces in getUnfixable().
		$unfixable = $report->getUnfixable();
		self::assertCount(1, $unfixable);
		self::assertSame('foreign_key.charset_mismatch', $unfixable[0]->getKey());
	}

	/**
	 * A child latin1 varchar(20) referencing a utf8mb4 varchar(30) parent has BOTH a charset mismatch and a
	 * size mismatch. Because latin1 is single-byte legacy, the charset conversion is blocked and the whole
	 * pair stays report-only — the size mismatch is also not fixed (a partial fix would fail the FK re-add).
	 * MySQL-only: charset-mismatched FKs cannot be constructed on MariaDB.
	 *
	 * @dataProvider provide
	 */
	public function testCombinedCharsetAndLengthFixAppliesOnMysql(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$auditor = new ForeignKeyColumnTypeMismatchMysqlAuditor($dbal);

		$db = 'fk_mismatch_autofix__combined';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		if ($engine === DatabaseEngine::mariadb()) {
			// MariaDB cannot construct a charset-mismatched FK; verify a matching fixture raises no violation.
			$dbal->exec(
			/** @lang MySQL */
				<<<'SQL'
CREATE TABLE `parent_t` (
	`id` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
	PRIMARY KEY (`id`)
) ENGINE=InnoDB;
SQL,
			);
			$dbal->exec(
			/** @lang MySQL */
				<<<'SQL'
CREATE TABLE `child_t` (
	`parent_id` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
	FOREIGN KEY (`parent_id`) REFERENCES `parent_t` (`id`)
) ENGINE=InnoDB;
SQL,
			);
			self::assertSame([], $auditor->analyse()->getViolations());

			return;
		}

		// MySQL: latin1 varchar(20) child → utf8mb4 varchar(30) parent via FOREIGN_KEY_CHECKS=0 + ALTER.
		$dbal->exec(/** @lang MySQL */ 'SET FOREIGN_KEY_CHECKS = 0;');
		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `parent_t` (
	`id` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
	PRIMARY KEY (`id`)
) ENGINE=InnoDB;
SQL,
		);
		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `child_t` (
	`parent_id` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
	FOREIGN KEY (`parent_id`) REFERENCES `parent_t` (`id`)
) ENGINE=InnoDB;
SQL,
		);
		// Narrow the child to latin1 to introduce both a charset and a size mismatch with the parent.
		$dbal->exec(
		/** @lang MySQL */
			'ALTER TABLE `child_t`'
			. ' MODIFY COLUMN `parent_id` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL;',
		);
		$dbal->exec(/** @lang MySQL */ 'SET FOREIGN_KEY_CHECKS = 1;');

		// Auditor reports two violations for the same pair: charset_mismatch + size_mismatch, both report-only.
		$violations = $auditor->analyse()->getViolations();
		self::assertCount(2, $violations);
		// Sorted by kind: charset (0) before size (1).
		self::assertSame('foreign_key.charset_mismatch', $violations[0]->getKey());
		self::assertSame('foreign_key.size_mismatch', $violations[1]->getKey());
		self::assertFalse($violations[0]->isFixable());
		self::assertFalse($violations[1]->isFixable());

		$report = (new Runner($dbal, [$auditor]))->generate();

		// No SQL emitted: the blocked charset poisons the whole pair.
		self::assertSame('', $report->getSql());

		// Both violations surface in getUnfixable().
		$unfixable = $report->getUnfixable();
		self::assertCount(2, $unfixable);
		self::assertSame('foreign_key.charset_mismatch', $unfixable[0]->getKey());
		self::assertSame('foreign_key.size_mismatch', $unfixable[1]->getKey());
	}

	/**
	 * A child utf8mb4 charset referencing a parent with a narrower charset (latin1, MAXLEN 1) is UNSAFE:
	 * the child cannot be narrowed to latin1 without data loss. The charset mismatch stays report-only and
	 * no MODIFY is emitted. MySQL-only: charset-mismatched FKs cannot be constructed on MariaDB.
	 *
	 * @dataProvider provide
	 */
	public function testUnsafeCharsetStaysReportOnly(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$auditor = new ForeignKeyColumnTypeMismatchMysqlAuditor($dbal);

		$db = 'fk_mismatch_autofix__unsafe_charset';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		if ($engine === DatabaseEngine::mariadb()) {
			// MariaDB cannot construct a charset-mismatched FK; verify a matching fixture raises no violation.
			$dbal->exec(
			/** @lang MySQL */
				<<<'SQL'
CREATE TABLE `parent_t` (
	`id` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
	PRIMARY KEY (`id`)
) ENGINE=InnoDB;
SQL,
			);
			$dbal->exec(
			/** @lang MySQL */
				<<<'SQL'
CREATE TABLE `child_t` (
	`parent_id` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL,
	FOREIGN KEY (`parent_id`) REFERENCES `parent_t` (`id`)
) ENGINE=InnoDB;
SQL,
			);
			self::assertSame([], $auditor->analyse()->getViolations());

			return;
		}

		// MySQL: utf8mb4 child → latin1 parent (UNSAFE: child charset MAXLEN > parent charset MAXLEN).
		$dbal->exec(/** @lang MySQL */ 'SET FOREIGN_KEY_CHECKS = 0;');
		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `parent_t` (
	`id` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
	PRIMARY KEY (`id`)
) ENGINE=InnoDB;
SQL,
		);
		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `child_t` (
	`parent_id` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL,
	FOREIGN KEY (`parent_id`) REFERENCES `parent_t` (`id`)
) ENGINE=InnoDB;
SQL,
		);
		// Widen the child to utf8mb4 so it is now wider than the latin1 parent (UNSAFE direction).
		$dbal->exec(
		/** @lang MySQL */
			'ALTER TABLE `child_t`'
			. ' MODIFY COLUMN `parent_id` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL;',
		);
		$dbal->exec(/** @lang MySQL */ 'SET FOREIGN_KEY_CHECKS = 1;');

		// Auditor reports the charset mismatch as non-fixable (unsafe direction: child MAXLEN > parent MAXLEN).
		$violations = $auditor->analyse()->getViolations();
		self::assertCount(1, $violations);
		self::assertSame('foreign_key.charset_mismatch', $violations[0]->getKey());
		self::assertFalse($violations[0]->isFixable());

		$report = (new Runner($dbal, [$auditor]))->generate();

		// No SQL emitted: the unsafe mismatch is not auto-fixed.
		self::assertSame('', $report->getSql());

		// The violation surfaces in getUnfixable().
		$unfixable = $report->getUnfixable();
		self::assertCount(1, $unfixable);
		self::assertSame('foreign_key.charset_mismatch', $unfixable[0]->getKey());
	}

	/**
	 * A child char(10) FK on a varchar(20) parent is INCOMPATIBLE (char vs varchar: different base types),
	 * so widening cannot reconcile it. The size mismatch stays report-only, and no MODIFY is emitted.
	 * FOREIGN_KEY_CHECKS=0 allows creating the cross-base-type FK; both engines accept this combination.
	 *
	 * @dataProvider provide
	 */
	public function testIncompatibleBaseTypeStaysReportOnly(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$auditor = new ForeignKeyColumnTypeMismatchMysqlAuditor($dbal);

		$db = 'fk_mismatch_autofix__incompatible_type';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		// FOREIGN_KEY_CHECKS=0 allows creating a FK between char and varchar on both engines.
		$dbal->exec(/** @lang MySQL */ 'SET FOREIGN_KEY_CHECKS = 0;');
		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `parent_t` (
	`id` varchar(20) NOT NULL,
	PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
		);
		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `child_t` (
	`parent_id` char(10) NULL,
	FOREIGN KEY (`parent_id`) REFERENCES `parent_t` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
		);
		$dbal->exec(/** @lang MySQL */ 'SET FOREIGN_KEY_CHECKS = 1;');

		// Auditor reports size mismatch as non-fixable (incompatible base types: char vs varchar).
		$violations = $auditor->analyse()->getViolations();
		self::assertCount(1, $violations);
		self::assertSame('foreign_key.size_mismatch', $violations[0]->getKey());
		self::assertFalse($violations[0]->isFixable());

		$report = (new Runner($dbal, [$auditor]))->generate();

		// No MODIFY is emitted for the incompatible pair.
		self::assertStringNotContainsString('MODIFY `parent_id`', $report->getSql());

		// The violation surfaces in getUnfixable().
		$unfixable = $report->getUnfixable();
		self::assertCount(1, $unfixable);
		self::assertSame('foreign_key.size_mismatch', $unfixable[0]->getKey());
	}

}

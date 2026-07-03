<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Auditor;

use Generator;
use Orisai\DbAudit\Auditor\NullableWithNoNullsMysqlAuditor;
use Orisai\DbAudit\Change\ColumnTargetChange;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Runner\Runner;
use Orisai\DbAudit\Schema\SchemaProvider;
use Orisai\DbAudit\Schema\TableExclude;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\AuditorRunner;
use Tests\Orisai\DbAudit\Helper\DbProvider;
use Tests\Orisai\DbAudit\Helper\MysqlShortcuts;

final class NullableWithNoNullsAuditorTest extends TestCase
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
	public function test(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$schema = new SchemaProvider($dbal);
		$auditor = new NullableWithNoNullsMysqlAuditor($schema);

		$key = 'nullable_with_no_nulls';

		$db = 'nullable_with_no_nulls';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		self::assertEquals([], AuditorRunner::analyse($schema, $auditor)->getViolations());

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `empty_table` (
	`a` int NOT NULL,
	`b` text NULL
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `full_table` (
	`a` int NOT NULL,
	`b` text NULL
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `full_table` (`a`, `b`) VALUES
(0, 'a'),
(0, null)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `no_nulls` (
	`a` int NULL,
	`b` text NULL
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `no_nulls` (`a`, `b`) VALUES
(0, ''),
(2, 'bar')
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `another_no_nulls` (
	`d` tinyint NULL,
	`c` varchar(255) NULL
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `another_no_nulls` (`d`, `c`) VALUES
(0, ''),
(2, 'bar')
SQL,
		);

		// The exact MODIFY definition (display widths, default charset) differs between MySQL and MariaDB, so
		// assert the stable fields and that each simple column is fixable with a column change; the generated SQL
		// is verified by applying it in testGeneratesAndAppliesNotNull().
		$report = AuditorRunner::analyse($schema, $auditor)->getViolations();
		$expected = [
			['another_no_nulls', 'c'],
			['another_no_nulls', 'd'],
			['no_nulls', 'a'],
			['no_nulls', 'b'],
		];
		self::assertCount(4, $report);
		foreach ($expected as $index => [$table, $column]) {
			$violation = $report[$index];
			self::assertSame($key, $violation->getKey());
			self::assertSame(
				"Column [$table][$column] is nullable but contains no nulls.",
				$violation->getMessage(),
			);
			$source = $violation->getSource();
			self::assertInstanceOf(ColumnViolationSource::class, $source);
			self::assertSame($table, $source->getTable());
			self::assertSame($column, $source->getColumn());
			self::assertTrue($violation->isFixable());
			$change = $violation->getChanges()[0] ?? null;
			self::assertInstanceOf(ColumnTargetChange::class, $change);
			self::assertSame($column, $change->getColumn());
		}

		self::assertEquals($report, AuditorRunner::analyse($schema, $auditor)->getViolations());

		$dbal->exec(
		/** @lang MySQL */
			'CREATE TABLE `excluded_1` (`a` int NULL)',
		);
		$dbal->exec(
		/** @lang MySQL */
			'INSERT INTO `excluded_1` (`a`) VALUES (1)',
		);

		$excludingSchema = new SchemaProvider($dbal, (new TableExclude())->withPattern('^excluded_'));
		$excludingAuditor = new NullableWithNoNullsMysqlAuditor($excludingSchema);
		foreach (AuditorRunner::analyse($excludingSchema, $excludingAuditor)->getViolations() as $violation) {
			self::assertStringNotContainsString('excluded_1', $violation->getMessage());
		}
	}

	/**
	 * @dataProvider provide
	 */
	public function testGeneratesAndAppliesNotNull(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$db = 'nullable_with_no_nulls_fix';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		$dbal->exec(
		/** @lang MySQL */
			'CREATE TABLE `t` (`a` int NULL, `b` varchar(50) NULL, `with_default` int NULL DEFAULT 5)',
		);
		$dbal->exec(/** @lang MySQL */ "INSERT INTO `t` (`a`, `b`, `with_default`) VALUES (1, 'x', 1), (2, 'y', 2)");

		$schema = new SchemaProvider($dbal);
		$report = (new Runner($schema, [new NullableWithNoNullsMysqlAuditor($schema)]))->generate();

		// `a` and `b` are simple -> fixed; `with_default` has a default -> skipped (not generated).
		self::assertSame(2, $report->getGeneratedCount());

		$shortcuts->applyScript($report->getSql());

		$nullability = [];
		foreach (
			$dbal->query(
			/** @lang MySQL */
				"SELECT COLUMN_NAME, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
				WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't'",
			) as $row
		) {
			$nullability[(string) $row['COLUMN_NAME']] = (string) $row['IS_NULLABLE'];
		}

		self::assertSame('NO', $nullability['a']);
		self::assertSame('NO', $nullability['b']);
		self::assertSame('YES', $nullability['with_default']);

		// Data is preserved.
		$count = $dbal->query(/** @lang MySQL */ 'SELECT COUNT(*) AS c FROM `t`');
		self::assertSame(2, (int) $count[0]['c']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testPreservesCommentWhenTighteningToNotNull(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$db = 'nullable_with_no_nulls_comment';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		$dbal->exec(
		/** @lang MySQL */
			"CREATE TABLE `t` (`a` int NULL, `note` varchar(50) NULL COMMENT 'Keep this comment')",
		);
		$dbal->exec(/** @lang MySQL */ "INSERT INTO `t` (`a`, `note`) VALUES (1, 'x'), (2, 'y')");

		$schema = new SchemaProvider($dbal);
		$sql = (new Runner($schema, [new NullableWithNoNullsMysqlAuditor($schema)]))->generate()->getSql();

		// The tightening MODIFY carries the column's existing comment: the sparse setNullable(false) delta merges
		// onto the current definition, which still holds the comment. Before deltas the auditor restated a full
		// definition without the comment and silently dropped it.
		self::assertStringContainsString("COMMENT 'Keep this comment'", $sql);

		$shortcuts->applyScript($sql);

		$columns = [];
		foreach (
			$dbal->query(
			/** @lang MySQL */
				"SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_COMMENT FROM INFORMATION_SCHEMA.COLUMNS
				WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't'",
			) as $row
		) {
			$columns[(string) $row['COLUMN_NAME']] = $row;
		}

		self::assertSame('NO', (string) $columns['note']['IS_NULLABLE']);
		self::assertSame('Keep this comment', (string) $columns['note']['COLUMN_COMMENT']);
		self::assertSame('NO', (string) $columns['a']['IS_NULLABLE']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testEmptyTableYieldsNoViolations(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$schema = new SchemaProvider($dbal);
		$auditor = new NullableWithNoNullsMysqlAuditor($schema);

		$db = 'nullable_with_no_nulls_empty';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		// A table with no rows trivially has zero NULLs in every nullable column; flagging those as
		// "nullable but contains no nulls" would be a false positive, so empty tables are skipped.
		$dbal->exec(/** @lang MySQL */ 'CREATE TABLE `empty` (`a` int NULL, `b` text NULL)');

		self::assertEquals([], AuditorRunner::analyse($schema, $auditor)->getViolations());
	}

}

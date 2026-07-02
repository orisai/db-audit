<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Auditor;

use Generator;
use Orisai\DbAudit\Auditor\EmptyColumnMysqlAuditor;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;
use Orisai\DbAudit\Schema\SchemaProvider;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\DbProvider;
use Tests\Orisai\DbAudit\Helper\MysqlShortcuts;

final class EmptyColumnMysqlAuditorTest extends TestCase
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
		$auditor = new EmptyColumnMysqlAuditor($schema);

		$key = 'empty_column';

		$db = 'empty_column';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		self::assertEquals([], $auditor->analyse()->getViolations());

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `empty_table` (
	`a` tinyint(1) NOT NULL,
	`b` tinyint(1) NULL
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `full_table` (
	`a` tinyint(1) NOT NULL,
	`b` tinyint(1) NULL
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `full_table` (`a`, `b`) VALUES
(1, 1),
(1, null)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `empty_column_null` (
	`a` tinyint(1) NOT NULL,
	`b` tinyint(1) NULL
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `empty_column_null` (`a`, `b`) VALUES
(1, null)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `empty_column_text` (
	`a` text NOT NULL
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `empty_column_text` (`a`) VALUES
('')
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `háčky čárky` (
	`háčky čárky` tinyint(1) NULL
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `háčky čárky` (`háčky čárky`) VALUES
(null)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `all_string_types` (
	`char` char(10) NULL,
	`varchar` varchar(255) NULL,
	`tinytext` tinytext NULL,
	`text` text NULL,
	`mediumtext` mediumtext NULL,
	`longtext` longtext NULL
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `all_string_types` (`char`, `varchar`, `tinytext`, `text`, `mediumtext`, `longtext`) VALUES
	('', '', '', '', '', ''),
	(null, null, null, null, null, null)
SQL,
		);

		$report = $auditor->analyse()->getViolations();
		self::assertEquals([
			new Violation(
				$key,
				'Column [all_string_types][char] is empty.',
				new ColumnViolationSource($db, null, 'all_string_types', 'char'),
			),
			new Violation(
				$key,
				'Column [all_string_types][longtext] is empty.',
				new ColumnViolationSource($db, null, 'all_string_types', 'longtext'),
			),
			new Violation(
				$key,
				'Column [all_string_types][mediumtext] is empty.',
				new ColumnViolationSource($db, null, 'all_string_types', 'mediumtext'),
			),
			new Violation(
				$key,
				'Column [all_string_types][text] is empty.',
				new ColumnViolationSource($db, null, 'all_string_types', 'text'),
			),
			new Violation(
				$key,
				'Column [all_string_types][tinytext] is empty.',
				new ColumnViolationSource($db, null, 'all_string_types', 'tinytext'),
			),
			new Violation(
				$key,
				'Column [all_string_types][varchar] is empty.',
				new ColumnViolationSource($db, null, 'all_string_types', 'varchar'),
			),
			new Violation(
				$key,
				'Column [empty_column_null][b] is empty.',
				new ColumnViolationSource($db, null, 'empty_column_null', 'b'),
			),
			new Violation(
				$key,
				'Column [empty_column_text][a] is empty.',
				new ColumnViolationSource($db, null, 'empty_column_text', 'a'),
			),
			new Violation(
				$key,
				'Column [háčky čárky][háčky čárky] is empty.',
				new ColumnViolationSource($db, null, 'háčky čárky', 'háčky čárky'),
			),
		], $report);
		self::assertEquals($report, $auditor->analyse()->getViolations());
	}

	/**
	 * @dataProvider provide
	 */
	public function testNonStringColumnIsEmptyOnlyWhenAllNull(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$schema = new SchemaProvider($dbal);
		$auditor = new EmptyColumnMysqlAuditor($schema);
		$key = 'empty_column';

		$db = 'empty_column_non_string';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		$dbal->exec(/** @lang MySQL */'CREATE TABLE `int_zero` (`n` int NULL)');
		$dbal->exec(
		/** @lang MySQL */
			'INSERT INTO `int_zero` (`n`) VALUES (0), (null)',
		);

		$dbal->exec(
		/** @lang MySQL */
			'CREATE TABLE `int_allnull` (`n` int NULL)',
		);
		$dbal->exec(
		/** @lang MySQL */
			'INSERT INTO `int_allnull` (`n`) VALUES (null), (null)',
		);

		$dbal->exec(
		/** @lang MySQL */
			'CREATE TABLE `str_empty` (`s` varchar(255) NULL)',
		);
		$dbal->exec(
		/** @lang MySQL */
			"INSERT INTO `str_empty` (`s`) VALUES (''), (null)",
		);

		self::assertEquals([
			new Violation(
				$key,
				'Column [int_allnull][n] is empty.',
				new ColumnViolationSource($db, null, 'int_allnull', 'n'),
			),
			new Violation(
				$key,
				'Column [str_empty][s] is empty.',
				new ColumnViolationSource($db, null, 'str_empty', 's'),
			),
		], $auditor->analyse()->getViolations());
	}

	/**
	 * @dataProvider provide
	 */
	public function testBacktickInIdentifiers(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$schema = new SchemaProvider($dbal);
		$auditor = new EmptyColumnMysqlAuditor($schema);
		$key = 'empty_column';

		$db = 'empty_column_backtick';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		// Both the table and the column names contain a backtick; bare-backtick interpolation in the dynamic
		// SQL would close the identifier early and break the generated query.
		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `we``ird` (
	`c``2` text NULL
)
SQL,
		);
		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `we``ird` (`c``2`) VALUES ('')
SQL,
		);

		self::assertEquals([
			new Violation(
				$key,
				'Column [we`ird][c`2] is empty.',
				new ColumnViolationSource($db, null, 'we`ird', 'c`2'),
			),
		], $auditor->analyse()->getViolations());
	}

	/**
	 * @dataProvider provide
	 */
	public function testViewsAreNotAudited(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$schema = new SchemaProvider($dbal);
		$auditor = new EmptyColumnMysqlAuditor($schema);
		$key = 'empty_column';

		$db = 'empty_column_view';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		$dbal->exec(/** @lang MySQL */ 'CREATE TABLE `base` (`c` text NULL)');
		$dbal->exec(/** @lang MySQL */ "INSERT INTO `base` (`c`) VALUES ('')");
		$dbal->exec(/** @lang MySQL */ 'CREATE VIEW `v_base` AS SELECT `c` FROM `base`');

		// The view column would also be reported as empty if views were audited as tables.
		self::assertEquals([
			new Violation(
				$key,
				'Column [base][c] is empty.',
				new ColumnViolationSource($db, null, 'base', 'c'),
			),
		], $auditor->analyse()->getViolations());
	}

}

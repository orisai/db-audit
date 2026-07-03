<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Auditor;

use Generator;
use Orisai\DbAudit\Auditor\BoolLikeColumnMysqlAuditor;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;
use Orisai\DbAudit\Schema\SchemaProvider;
use Orisai\DbAudit\Schema\TableExclude;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\AuditorRunner;
use Tests\Orisai\DbAudit\Helper\DbProvider;
use Tests\Orisai\DbAudit\Helper\MysqlShortcuts;

final class BoolLikeColumnMysqlAuditorTest extends TestCase
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
		$auditor = new BoolLikeColumnMysqlAuditor($schema);

		$key = 'bool_like_column';

		$db = 'bool_like_column';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		self::assertEquals([], AuditorRunner::analyse($schema, $auditor)->getViolations());

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `empty_table` (
	`tinyint` tinyint NOT NULL,
	`tinyint_null` tinyint unsigned NULL,
	`int` int NOT NULL,
	`int_null` int unsigned NULL
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `out_of_range_columns` (
	`tinyint` tinyint unsigned NOT NULL,
	`tinyint_2` tinyint NULL,
	`int` int unsigned NOT NULL,
	`int_2` int NULL
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `out_of_range_columns` (`tinyint`, `tinyint_2`, `int`, `int_2`) VALUES
	(0, 0, 0, 0),
	(1, 1, 1, 1),
	(2, -1, 2, -1)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `no_checks` (
	`tinyint` tinyint unsigned NULL,
	`int` int(1) NULL
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `no_checks` (`tinyint`, `int`) VALUES
	(1, 1),
	(0, 0),
	(null, null)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `checks` (
	`tinyint` tinyint unsigned NULL,
	`tinyint_2` tinyint unsigned NULL,
	`tinyint_3` tinyint unsigned NOT NULL,
	`tinyint_4` tinyint unsigned NOT NULL,
	`int` int(1) NOT NULL,
	CHECK ( `tinyint` IN (0, 1)),
	CHECK ( `tinyint_2` IN (1, 0)),
	CHECK(`tinyint_3`IN(0,1)),
	CHECK ( 0=0 AND `tinyint_4` IN (0, 1) AND 1=1),
	CHECK ( `int` IN (0, 1))
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `checks` (`tinyint`, `tinyint_2`, `tinyint_3`, `tinyint_4`, `int`) VALUES
	(1, 1, 1, 1, 1),
	(0, 0, 0, 0, 0),
	(null, null, 0, 0, 0)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `non_tiny_types` (
	`smallint` smallint unsigned NOT NULL,
	`mediumint` mediumint unsigned NOT NULL,
	`int` int unsigned NOT NULL,
	`bigint` bigint unsigned NOT NULL,
	CHECK ( `smallint` IN (0, 1)),
	CHECK ( `mediumint` IN (0, 1)),
	CHECK ( `int` IN (0, 1)),
	CHECK ( `bigint` IN (0, 1))
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `non_tiny_types` (`smallint`, `mediumint`, `int`, `bigint`) VALUES
	(1, 1, 1, 1),
	(0, 0, 0, 0)
SQL,
		);

		$mariadb = $engine->value === 'mariadb';

		// MariaDB keeps integer display widths in COLUMN_TYPE where MySQL 8 dropped them; the auditor echoes
		// COLUMN_TYPE into the violation, so the expected type strings are engine-specific.
		$intType = $mariadb ? 'int(1)' : 'int';
		$tinyintUnsigned = $mariadb ? 'tinyint(3) unsigned' : 'tinyint unsigned';
		$bigintUnsigned = $mariadb ? 'bigint(20) unsigned' : 'bigint unsigned';
		$intUnsigned = $mariadb ? 'int(10) unsigned' : 'int unsigned';
		$mediumintUnsigned = $mariadb ? 'mediumint(8) unsigned' : 'mediumint unsigned';
		$smallintUnsigned = $mariadb ? 'smallint(5) unsigned' : 'smallint unsigned';

		$report = AuditorRunner::analyse($schema, $auditor)->getViolations();
		self::assertEquals($report, AuditorRunner::analyse($schema, $auditor)->getViolations());
		self::assertEquals([
			new Violation(
				$key,
				"Column [checks][int] (Column type: '$intType') contains only 0 and 1 but is not defined as tinyint.",
				(new ColumnViolationSource($db, null, 'checks', 'int'))
					->setColumnType($intType),
			),
			new Violation(
				$key,
				"Column [no_checks][int] (Column type: '$intType') contains only 0 and 1 but is not defined as tinyint.",
				(new ColumnViolationSource($db, null, 'no_checks', 'int'))
					->setColumnType($intType),
			),
			new Violation(
				$key,
				"Column [no_checks][int] (Column type: '$intType') contains only 0 and 1 but the table does not define CHECK ( `int` IN (0, 1)).",
				(new ColumnViolationSource($db, null, 'no_checks', 'int'))
					->setColumnType($intType),
			),
			new Violation(
				$key,
				"Column [no_checks][tinyint] (Column type: '$tinyintUnsigned') "
				. 'contains only 0 and 1 but the table does not define CHECK ( `tinyint` IN (0, 1)).',
				(new ColumnViolationSource($db, null, 'no_checks', 'tinyint'))
					->setColumnType($tinyintUnsigned),
			),
			new Violation(
				$key,
				"Column [non_tiny_types][bigint] (Column type: '$bigintUnsigned') contains only 0 and 1 but is not defined as tinyint.",
				(new ColumnViolationSource($db, null, 'non_tiny_types', 'bigint'))
					->setColumnType($bigintUnsigned),
			),
			new Violation(
				$key,
				"Column [non_tiny_types][int] (Column type: '$intUnsigned') contains only 0 and 1 but is not defined as tinyint.",
				(new ColumnViolationSource($db, null, 'non_tiny_types', 'int'))
					->setColumnType($intUnsigned),
			),
			new Violation(
				$key,
				"Column [non_tiny_types][mediumint] (Column type: '$mediumintUnsigned') contains only 0 and 1 but is not defined as tinyint.",
				(new ColumnViolationSource($db, null, 'non_tiny_types', 'mediumint'))
					->setColumnType($mediumintUnsigned),
			),
			new Violation(
				$key,
				"Column [non_tiny_types][smallint] (Column type: '$smallintUnsigned') contains only 0 and 1 but is not defined as tinyint.",
				(new ColumnViolationSource($db, null, 'non_tiny_types', 'smallint'))
					->setColumnType($smallintUnsigned),
			),
		], $report);

		$dbal->exec(
		/** @lang MySQL */
			'CREATE TABLE `excluded_1` (`a` int NULL)',
		);
		$dbal->exec(
		/** @lang MySQL */
			'INSERT INTO `excluded_1` (`a`) VALUES (0), (1)',
		);

		$excludingSchema = new SchemaProvider($dbal, (new TableExclude())->withPattern('^excluded_'));
		$excludingAuditor = new BoolLikeColumnMysqlAuditor($excludingSchema);
		foreach (AuditorRunner::analyse($excludingSchema, $excludingAuditor)->getViolations() as $violation) {
			self::assertStringNotContainsString('excluded_1', $violation->getMessage());
		}
	}

	/**
	 * @dataProvider provide
	 */
	public function testValidCheckAndAllNullAreNotFlagged(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$schema = new SchemaProvider($dbal);
		$auditor = new BoolLikeColumnMysqlAuditor($schema);

		$db = 'bool_like_column_check';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		// MariaDB stores CHECK_CLAUSE without the wrapping parentheses MySQL adds, so a leading-paren match
		// drops the constraint and reports a false "missing CHECK".
		$dbal->exec(
		/** @lang MySQL */
			'CREATE TABLE `checks` (`flag` tinyint unsigned NULL, CHECK ( `flag` IN (0, 1)))',
		);
		$dbal->exec(
		/** @lang MySQL */
			'INSERT INTO `checks` (`flag`) VALUES (0), (1)',
		);

		// A column holding only NULLs is not bool-like: NULL NOT IN (0, 1) is never counted.
		$dbal->exec(
		/** @lang MySQL */
			'CREATE TABLE `allnull` (`flag` tinyint NULL)',
		);
		$dbal->exec(
		/** @lang MySQL */
			'INSERT INTO `allnull` (`flag`) VALUES (null), (null)',
		);

		self::assertEquals([], AuditorRunner::analyse($schema, $auditor)->getViolations());
	}

	/**
	 * @dataProvider provide
	 */
	public function testSurvivesLeftoverProcedure(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$schema = new SchemaProvider($dbal);
		$auditor = new BoolLikeColumnMysqlAuditor($schema);
		$key = 'bool_like_column';

		$db = 'bool_like_column_leftover';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		// A stray procedure sharing the name of the old implementation's routine must not interfere with the
		// procedure-free, profiler-driven analysis.
		$dbal->exec(
		/** @lang MySQL */
			'CREATE PROCEDURE OrisaiDbAudit_FindBoolLikeColumns() BEGIN END',
		);

		$dbal->exec(
		/** @lang MySQL */
			'CREATE TABLE `flags` (`flag` tinyint unsigned NULL, CHECK ( `flag` IN (0, 1)))',
		);
		$dbal->exec(
		/** @lang MySQL */
			'INSERT INTO `flags` (`flag`) VALUES (0), (1)',
		);

		// A tinyint with a valid CHECK yields no violations, unaffected by the unrelated stray procedure.
		self::assertEquals([], AuditorRunner::analyse($schema, $auditor)->getViolations());

		// Same again, now with a column missing its CHECK so a violation is produced; the integer display
		// width differs between MySQL and MariaDB, so assert on the stable fields rather than the type string.
		$dbal->exec(/** @lang MySQL */ 'CREATE TABLE `no_check` (`flag` tinyint unsigned NULL)');
		$dbal->exec(/** @lang MySQL */ 'INSERT INTO `no_check` (`flag`) VALUES (0), (1)');

		$violations = AuditorRunner::analyse($schema, $auditor)->getViolations();
		self::assertCount(1, $violations);
		$violation = $violations[0];
		self::assertSame($key, $violation->getKey());
		$source = $violation->getSource();
		self::assertInstanceOf(ColumnViolationSource::class, $source);
		self::assertSame($db, $source->getDatabase());
		self::assertSame('no_check', $source->getTable());
		self::assertSame('flag', $source->getColumn());
	}

}

<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Auditor;

use Generator;
use Orisai\DbAudit\Auditor\MixedEmptyValuesMysqlAuditor;
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

final class MixedEmptyValuesMysqlAuditorTest extends TestCase
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
		$auditor = new MixedEmptyValuesMysqlAuditor($schema);

		$key = 'mixed_empty_values';

		$db = 'mixed_empty_values';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		self::assertEquals([], AuditorRunner::analyse($schema, $auditor)->getViolations());

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `empty_table` (
	`string` varchar(255) NOT NULL,
	`string_null` varchar(255) NULL
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `full_table` (
	`string` varchar(255) NOT NULL,
	`string_null` varchar(255) NULL
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `full_table` (`string`, `string_null`) VALUES
	('foo', 'bar'),
	('', ''),
	('', null)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `all_types` (
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
INSERT INTO `all_types` (`char`, `varchar`, `tinytext`, `text`, `mediumtext`, `longtext`) VALUES
	('', '', '', '', '', ''),
	(null, null, null, null, null, null)
SQL,
		);

		$report = AuditorRunner::analyse($schema, $auditor)->getViolations();
		self::assertEquals($report, AuditorRunner::analyse($schema, $auditor)->getViolations());
		self::assertEquals([
			new Violation(
				$key,
				'Column [all_types][char] contains mixed empty values.',
				new ColumnViolationSource($db, null, 'all_types', 'char'),
			),
			new Violation(
				$key,
				'Column [all_types][longtext] contains mixed empty values.',
				new ColumnViolationSource($db, null, 'all_types', 'longtext'),
			),
			new Violation(
				$key,
				'Column [all_types][mediumtext] contains mixed empty values.',
				new ColumnViolationSource($db, null, 'all_types', 'mediumtext'),
			),
			new Violation(
				$key,
				'Column [all_types][text] contains mixed empty values.',
				new ColumnViolationSource($db, null, 'all_types', 'text'),
			),
			new Violation(
				$key,
				'Column [all_types][tinytext] contains mixed empty values.',
				new ColumnViolationSource($db, null, 'all_types', 'tinytext'),
			),
			new Violation(
				$key,
				'Column [all_types][varchar] contains mixed empty values.',
				new ColumnViolationSource($db, null, 'all_types', 'varchar'),
			),
			new Violation(
				$key,
				'Column [full_table][string_null] contains mixed empty values.',
				new ColumnViolationSource($db, null, 'full_table', 'string_null'),
			),
		], $report);

		$dbal->exec(
		/** @lang MySQL */
			'CREATE TABLE `excluded_1` (`a` varchar(255) NULL)',
		);
		$dbal->exec(
		/** @lang MySQL */
			"INSERT INTO `excluded_1` (`a`) VALUES (''), (null)",
		);

		$excludingSchema = new SchemaProvider($dbal, (new TableExclude())->withPattern('^excluded_'));
		$excludingAuditor = new MixedEmptyValuesMysqlAuditor($excludingSchema);
		foreach (AuditorRunner::analyse($excludingSchema, $excludingAuditor)->getViolations() as $violation) {
			self::assertStringNotContainsString('excluded_1', $violation->getMessage());
		}
	}

	/**
	 * @dataProvider provide
	 */
	public function testCharColumnIsDetected(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$schema = new SchemaProvider($dbal);
		$auditor = new MixedEmptyValuesMysqlAuditor($schema);
		$key = 'mixed_empty_values';

		$db = 'mixed_empty_values_char';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		$dbal->exec(/** @lang MySQL */'CREATE TABLE `t` (`c` char(10) NULL)');
		$dbal->exec(
		/** @lang MySQL */
			"INSERT INTO `t` (`c`) VALUES ('foo'), (''), (null)",
		);

		self::assertEquals([
			new Violation(
				$key,
				'Column [t][c] contains mixed empty values.',
				new ColumnViolationSource($db, null, 't', 'c'),
			),
		], AuditorRunner::analyse($schema, $auditor)->getViolations());
	}

	/**
	 * @dataProvider provide
	 */
	public function testSurvivesLeftoverProcedure(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$schema = new SchemaProvider($dbal);
		$auditor = new MixedEmptyValuesMysqlAuditor($schema);
		$key = 'mixed_empty_values';

		$db = 'mixed_empty_values_leftover';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		// A stray procedure sharing the name of the old implementation's routine must not interfere with the
		// procedure-free, profiler-driven analysis.
		$dbal->exec(
		/** @lang MySQL */
			'CREATE PROCEDURE OrisaiDbAudit_FindMixedEmptyColumns() BEGIN END',
		);

		$dbal->exec(/** @lang MySQL */ 'CREATE TABLE `t` (`c` varchar(255) NULL)');
		$dbal->exec(
		/** @lang MySQL */
			"INSERT INTO `t` (`c`) VALUES ('foo'), (''), (null)",
		);

		self::assertEquals([
			new Violation(
				$key,
				'Column [t][c] contains mixed empty values.',
				new ColumnViolationSource($db, null, 't', 'c'),
			),
		], AuditorRunner::analyse($schema, $auditor)->getViolations());
	}

}

<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Auditor;

use Generator;
use Orisai\DbAudit\Auditor\MixedEmptyValuesMysqlAuditor;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;
use PHPUnit\Framework\TestCase;
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
		$auditor = new MixedEmptyValuesMysqlAuditor($dbal);

		$key = 'mixed_empty_values';

		$db = 'mixed_empty_values';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		self::assertEquals([], $auditor->analyse()->getViolations());

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

		$report = $auditor->analyse()->getViolations();
		self::assertEquals($report, $auditor->analyse()->getViolations());
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
	}

	/**
	 * @dataProvider provide
	 */
	public function testCharColumnIsDetected(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$auditor = new MixedEmptyValuesMysqlAuditor($dbal);
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
		], $auditor->analyse()->getViolations());
	}

	/**
	 * @dataProvider provide
	 */
	public function testSurvivesLeftoverProcedure(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$auditor = new MixedEmptyValuesMysqlAuditor($dbal);
		$key = 'mixed_empty_values';

		$db = 'mixed_empty_values_leftover';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		// Simulate a procedure left over from a run killed before cleanup(); createProcedure() must DROP it first.
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
		], $auditor->analyse()->getViolations());
	}

}

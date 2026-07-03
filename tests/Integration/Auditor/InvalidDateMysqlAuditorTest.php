<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Auditor;

use Generator;
use Orisai\DbAudit\Auditor\InvalidDateMysqlAuditor;
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

final class InvalidDateMysqlAuditorTest extends TestCase
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
		$auditor = new InvalidDateMysqlAuditor($schema);

		$db = 'invalid_date';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		self::assertEquals([], AuditorRunner::analyse($schema, $auditor)->getViolations());

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `b` (
	`datetime` DATETIME NULL
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `a` (
	`datetime_2` DATETIME NULL,
	`datetime` DATETIME NULL,
	`date_2` DATE NULL,
	`date` DATE NULL,
	`timestamp_2` TIMESTAMP NULL,
	`timestamp` TIMESTAMP NULL
)
SQL,
		);

		//TODO - tady otestovat všechny validní hodnoty

		self::assertEquals([], AuditorRunner::analyse($schema, $auditor)->getViolations());

		//TODO - tady otestovat všechny nevalidní hodnoty
		//		- nastavit sql mód

		$report = AuditorRunner::analyse($schema, $auditor)->getViolations();
		self::assertEquals([
			// TODO - tests
		], $report);
		self::assertEquals($report, AuditorRunner::analyse($schema, $auditor)->getViolations());

		$dbal->exec(
		/** @lang MySQL */
			'CREATE TABLE `excluded_1` (`created` DATETIME NULL)',
		);

		$dbal->exec('SET @orisai_sql_mode = @@SESSION.sql_mode');
		$dbal->exec("SET SESSION sql_mode = ''");
		$dbal->exec(
		/** @lang MySQL */
			"INSERT INTO `excluded_1` (`created`) VALUES ('2024-00-01 00:00:00')",
		);
		$dbal->exec('SET SESSION sql_mode = @orisai_sql_mode');

		$excludingSchema = new SchemaProvider($dbal, (new TableExclude())->withPattern('^excluded_'));
		$excludingAuditor = new InvalidDateMysqlAuditor($excludingSchema);
		foreach (AuditorRunner::analyse($excludingSchema, $excludingAuditor)->getViolations() as $violation) {
			self::assertStringNotContainsString('excluded_1', $violation->getMessage());
		}
	}

	/**
	 * @dataProvider provide
	 */
	public function testReservedWordAndHyphenatedTableNames(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$schema = new SchemaProvider($dbal);
		$auditor = new InvalidDateMysqlAuditor($schema);
		$key = 'invalid_date';

		$db = 'invalid_date_reserved';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		// Allow zero dates to be stored so the auditor has something to detect.
		$dbal->exec(/** @lang MySQL */ "SET sql_mode = ''");

		// A reserved word and a hyphenated name both require backtick-quoting in the dynamic FROM clause.
		$dbal->exec(/** @lang MySQL */ 'CREATE TABLE `order` (`date` DATE NULL)');
		$dbal->exec(/** @lang MySQL */ "INSERT INTO `order` (`date`) VALUES ('0000-00-00')");

		$dbal->exec(/** @lang MySQL */ 'CREATE TABLE `my-dates` (`date` DATE NULL)');
		$dbal->exec(/** @lang MySQL */ "INSERT INTO `my-dates` (`date`) VALUES ('0000-00-00')");

		self::assertEquals([
			new Violation(
				$key,
				'Column [my-dates][date] contains invalid dates.',
				new ColumnViolationSource($db, null, 'my-dates', 'date'),
			),
			new Violation(
				$key,
				'Column [order][date] contains invalid dates.',
				new ColumnViolationSource($db, null, 'order', 'date'),
			),
		], AuditorRunner::analyse($schema, $auditor)->getViolations());
	}

}

<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Auditor;

use Generator;
use Orisai\DbAudit\Auditor\InvalidDateMysqlAuditor;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;
use PHPUnit\Framework\TestCase;
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
		$auditor = new InvalidDateMysqlAuditor($dbal);

		$db = 'invalid_date';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		self::assertEquals([], $auditor->analyse()->getViolations());

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

		self::assertEquals([], $auditor->analyse()->getViolations());

		//TODO - tady otestovat všechny nevalidní hodnoty
		//		- nastavit sql mód

		$report = $auditor->analyse()->getViolations();
		self::assertEquals([
			// TODO - tests
		], $report);
		self::assertEquals($report, $auditor->analyse()->getViolations());
	}

	/**
	 * @dataProvider provide
	 */
	public function testReservedWordAndHyphenatedTableNames(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$auditor = new InvalidDateMysqlAuditor($dbal);
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
		], $auditor->analyse()->getViolations());
	}

}

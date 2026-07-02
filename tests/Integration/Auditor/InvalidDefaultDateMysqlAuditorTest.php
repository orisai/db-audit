<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Auditor;

use Generator;
use Orisai\DbAudit\Auditor\InvalidDefaultDateMysqlAuditor;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;
use Orisai\DbAudit\Schema\SchemaProvider;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\DbProvider;
use Tests\Orisai\DbAudit\Helper\MysqlShortcuts;
use function usort;

final class InvalidDefaultDateMysqlAuditorTest extends TestCase
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
		$auditor = new InvalidDefaultDateMysqlAuditor($schema);

		$key = 'invalid_default_date';

		$db = 'invalid_default_date';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		$mariadb = $engine->value === 'mariadb';

		//TODO - kontrolovat i nevalidní datumy, nejen nulové? případně přejmenovat auditor
		$dbal->exec(/** @lang MySQL */ "SET sql_mode = 'ALLOW_INVALID_DATES'");

		self::assertEquals([], $auditor->analyse()->getViolations());

		$dbal->exec(
			/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `a` (
	`valid_date` DATE DEFAULT '2024-02-09',
	`valid_date_no_default` DATE,
	`invalid_date` DATE DEFAULT '0000-00-00',
	`invalid_date_year` DATE DEFAULT '0000-02-09',
	`invalid_date_month` DATE DEFAULT '2024-00-09',
	`invalid_date_day` DATE DEFAULT '2024-02-00',

	`valid_datetime` DATETIME DEFAULT '2010-08-08 00:00:00',
	`valid_datetime_no_default` DATETIME,
	`invalid_datetime` DATETIME DEFAULT '0000-00-00 00:00:00',
	`invalid_datetime_year` DATETIME DEFAULT '0000-08-08 00:00:00',
	`invalid_datetime_month` DATETIME DEFAULT '2010-00-08 00:00:00',
	`invalid_datetime_day` DATETIME DEFAULT '2010-08-00 00:00:00',

	`valid_timestamp` TIMESTAMP DEFAULT '2010-08-08 00:00:00',
	`valid_timestamp_no_default` TIMESTAMP,
	-- `invalid_timestamp_year` TIMESTAMP DEFAULT '0000-08-08 00:00:00', -- not possible
	`invalid_timestamp` TIMESTAMP DEFAULT '0000-00-00 00:00:00'
);
SQL,
		);

		// A TIMESTAMP whose default has a zero month or day (but a non-zero year) is rejected at CREATE by
		// MariaDB even under ALLOW_INVALID_DATES; MySQL accepts it and silently stores it as the zero timestamp.
		// Add these MySQL-only so both engines exercise every column they actually support.
		if (!$mariadb) {
			$dbal->exec(
				/** @lang MySQL */
				<<<'SQL'
ALTER TABLE `a`
	ADD COLUMN `invalid_timestamp_month` TIMESTAMP DEFAULT '2010-00-08 00:00:00',
	ADD COLUMN `invalid_timestamp_day` TIMESTAMP DEFAULT '2010-08-00 00:00:00';
SQL,
			);
		}

		$dbal->exec(
			/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `b` (
	`invalid_date` DATE DEFAULT '0000-00-00'
);
SQL,
		);

		// MySQL stores zero/invalid date defaults bare; MariaDB single-quotes the stored default. The auditor
		// echoes COLUMN_DEFAULT verbatim, so the expected value is engine-specific.
		$default = static fn (string $value): string => $mariadb ? "'$value'" : $value;

		$expected = [
			new Violation(
				$key,
				'Invalid default value ' . $default('0000-00-00') . " in [a][invalid_date] (Column type: 'date')",
				(new ColumnViolationSource($db, null, 'a', 'invalid_date'))
					->setColumnType('date'),
			),
			new Violation(
				$key,
				'Invalid default value ' . $default('2024-02-00') . " in [a][invalid_date_day] (Column type: 'date')",
				(new ColumnViolationSource($db, null, 'a', 'invalid_date_day'))
					->setColumnType('date'),
			),
			new Violation(
				$key,
				'Invalid default value ' . $default('2024-00-09') . " in [a][invalid_date_month] (Column type: 'date')",
				(new ColumnViolationSource($db, null, 'a', 'invalid_date_month'))
					->setColumnType('date'),
			),
			new Violation(
				$key,
				'Invalid default value ' . $default('0000-02-09') . " in [a][invalid_date_year] (Column type: 'date')",
				(new ColumnViolationSource($db, null, 'a', 'invalid_date_year'))
					->setColumnType('date'),
			),
			new Violation(
				$key,
				'Invalid default value ' . $default('0000-00-00 00:00:00') . " in [a][invalid_datetime] (Column type: 'datetime')",
				(new ColumnViolationSource($db, null, 'a', 'invalid_datetime'))
					->setColumnType('datetime'),
			),
			new Violation(
				$key,
				'Invalid default value ' . $default('2010-08-00 00:00:00') . " in [a][invalid_datetime_day] (Column type: 'datetime')",
				(new ColumnViolationSource($db, null, 'a', 'invalid_datetime_day'))
					->setColumnType('datetime'),
			),
			new Violation(
				$key,
				'Invalid default value ' . $default('2010-00-08 00:00:00') . " in [a][invalid_datetime_month] (Column type: 'datetime')",
				(new ColumnViolationSource($db, null, 'a', 'invalid_datetime_month'))
					->setColumnType('datetime'),
			),
			new Violation(
				$key,
				'Invalid default value ' . $default('0000-08-08 00:00:00') . " in [a][invalid_datetime_year] (Column type: 'datetime')",
				(new ColumnViolationSource($db, null, 'a', 'invalid_datetime_year'))
					->setColumnType('datetime'),
			),
			new Violation(
				$key,
				'Invalid default value ' . $default('0000-00-00 00:00:00') . " in [a][invalid_timestamp] (Column type: 'timestamp')",
				(new ColumnViolationSource($db, null, 'a', 'invalid_timestamp'))
					->setColumnType('timestamp'),
			),
			new Violation(
				$key,
				'Invalid default value ' . $default('0000-00-00') . " in [b][invalid_date] (Column type: 'date')",
				(new ColumnViolationSource($db, null, 'b', 'invalid_date'))
					->setColumnType('date'),
			),
		];

		if (!$mariadb) {
			// MySQL coerced both zero-month/day timestamp defaults to the zero timestamp.
			$expected[] = new Violation(
				$key,
				"Invalid default value 0000-00-00 00:00:00 in [a][invalid_timestamp_day] (Column type: 'timestamp')",
				(new ColumnViolationSource($db, null, 'a', 'invalid_timestamp_day'))
					->setColumnType('timestamp'),
			);
			$expected[] = new Violation(
				$key,
				"Invalid default value 0000-00-00 00:00:00 in [a][invalid_timestamp_month] (Column type: 'timestamp')",
				(new ColumnViolationSource($db, null, 'a', 'invalid_timestamp_month'))
					->setColumnType('timestamp'),
			);
		}

		// MySQL and MariaDB sort INFORMATION_SCHEMA column names under different collations, so compare the
		// violation sets order-independently by their (unique) messages.
		$byMessage = static fn (Violation $a, Violation $b): int => $a->getMessage() <=> $b->getMessage();

		$result = $auditor->analyse()->getViolations();
		usort($result, $byMessage);
		usort($expected, $byMessage);
		self::assertEquals($expected, $result);
	}

	/**
	 * @dataProvider provide
	 */
	public function testZeroYearPatternDoesNotOverMatch(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$schema = new SchemaProvider($dbal);
		$auditor = new InvalidDefaultDateMysqlAuditor($schema);
		$key = 'invalid_default_date';

		$db = 'invalid_default_date_year';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		$dbal->exec(/** @lang MySQL */ "SET sql_mode = 'ALLOW_INVALID_DATES'");

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `a` (
	`year_ending_in_zero` DATE DEFAULT '2000-03-15',
	`valid` DATE DEFAULT '2024-01-01',
	`genuine_zero` DATE DEFAULT '0000-00-00'
);
SQL,
		);

		// MySQL stores the string default bare, MariaDB stores it single-quoted.
		$default = $engine->value === 'mariadb' ? "'0000-00-00'" : '0000-00-00';

		self::assertEquals([
			new Violation(
				$key,
				"Invalid default value $default in [a][genuine_zero] (Column type: 'date')",
				(new ColumnViolationSource($db, null, 'a', 'genuine_zero'))
					->setColumnType('date'),
			),
		], $auditor->analyse()->getViolations());
	}

}

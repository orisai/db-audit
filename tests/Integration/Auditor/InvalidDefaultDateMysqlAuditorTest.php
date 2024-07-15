<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Auditor;

use Dibi\Connection as DibiConnection;
use Generator;
use Nextras\Dbal\Connection as NextrasConnection;
use Orisai\DbAudit\Auditor\InvalidDefaultDateMysqlAuditor;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Dbal\DibiAdapter;
use Orisai\DbAudit\Dbal\NextrasAdapter;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\MysqlConnectionConfig;
use Tests\Orisai\DbAudit\Helper\MysqlShortcuts;

final class InvalidDefaultDateMysqlAuditorTest extends TestCase
{

	public function provide(): Generator
	{
		$config = new MysqlConnectionConfig(
			'127.0.0.1',
			'root',
			'root',
			(int) '3306',
		);

		yield [
			new DibiAdapter(new DibiConnection($config->toDibi())),
		];

		yield [
			new NextrasAdapter(new NextrasConnection($config->toNextras())),
		];
	}

	/**
	 * @dataProvider provide
	 */
	public function test(DbalAdapter $dbal): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$auditor = new InvalidDefaultDateMysqlAuditor($dbal);

		$key = $auditor::getKey();
		self::assertSame('invalid_default_date', $key);

		$db = 'invalid_default_date';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		//TODO - kontrolovat i nevalidní datumy, nejen nulové? případně přejmenovat auditor
		$dbal->exec('SET sql_mode = `ALLOW_INVALID_DATES`');

		self::assertEquals([], $auditor->analyse());

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
	`invalid_timestamp` TIMESTAMP DEFAULT '0000-00-00 00:00:00',
	-- `invalid_timestamp_year` TIMESTAMP DEFAULT '0000-08-08 00:00:00', -- not possible
	`invalid_timestamp_month` TIMESTAMP DEFAULT '2010-00-08 00:00:00',
	`invalid_timestamp_day` TIMESTAMP DEFAULT '2010-08-00 00:00:00'
);
SQL,
		);

		$dbal->exec(
			/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `b` (
	`invalid_date` DATE DEFAULT '0000-00-00'
);
SQL,
		);

		$result = $auditor->analyse();
		self::assertEquals([
			new Violation(
				$key,
				"Invalid default value 0000-00-00 in [a][invalid_date] (Column type: 'date')",
				(new ColumnViolationSource($db, null, 'a', 'invalid_date'))
					->setColumnType('date'),
			),
			new Violation(
				$key,
				"Invalid default value 2024-02-00 in [a][invalid_date_day] (Column type: 'date')",
				(new ColumnViolationSource($db, null, 'a', 'invalid_date_day'))
					->setColumnType('date'),
			),
			new Violation(
				$key,
				"Invalid default value 2024-00-09 in [a][invalid_date_month] (Column type: 'date')",
				(new ColumnViolationSource($db, null, 'a', 'invalid_date_month'))
					->setColumnType('date'),
			),
			new Violation(
				$key,
				"Invalid default value 0000-02-09 in [a][invalid_date_year] (Column type: 'date')",
				(new ColumnViolationSource($db, null, 'a', 'invalid_date_year'))
					->setColumnType('date'),
			),
			new Violation(
				$key,
				"Invalid default value 0000-00-00 00:00:00 in [a][invalid_datetime] (Column type: 'datetime')",
				(new ColumnViolationSource($db, null, 'a', 'invalid_datetime'))
					->setColumnType('datetime'),
			),
			new Violation(
				$key,
				"Invalid default value 2010-08-00 00:00:00 in [a][invalid_datetime_day] (Column type: 'datetime')",
				(new ColumnViolationSource($db, null, 'a', 'invalid_datetime_day'))
					->setColumnType('datetime'),
			),
			new Violation(
				$key,
				"Invalid default value 2010-00-08 00:00:00 in [a][invalid_datetime_month] (Column type: 'datetime')",
				(new ColumnViolationSource($db, null, 'a', 'invalid_datetime_month'))
					->setColumnType('datetime'),
			),
			new Violation(
				$key,
				"Invalid default value 0000-08-08 00:00:00 in [a][invalid_datetime_year] (Column type: 'datetime')",
				(new ColumnViolationSource($db, null, 'a', 'invalid_datetime_year'))
					->setColumnType('datetime'),
			),
			new Violation(
				$key,
				"Invalid default value 0000-00-00 00:00:00 in [a][invalid_timestamp] (Column type: 'timestamp')",
				(new ColumnViolationSource($db, null, 'a', 'invalid_timestamp'))
					->setColumnType('timestamp'),
			),
			new Violation(
				$key,
				"Invalid default value 0000-00-00 00:00:00 in [a][invalid_timestamp_day] (Column type: 'timestamp')",
				(new ColumnViolationSource($db, null, 'a', 'invalid_timestamp_day'))
					->setColumnType('timestamp'),
			),
			new Violation(
				$key,
				"Invalid default value 0000-00-00 00:00:00 in [a][invalid_timestamp_month] (Column type: 'timestamp')",
				(new ColumnViolationSource($db, null, 'a', 'invalid_timestamp_month'))
					->setColumnType('timestamp'),
			),
			new Violation(
				$key,
				"Invalid default value 0000-00-00 in [b][invalid_date] (Column type: 'date')",
				(new ColumnViolationSource($db, null, 'b', 'invalid_date'))
					->setColumnType('date'),
			),
		], $result);
	}

}

<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Auditor;

use Dibi\Connection as DibiConnection;
use Generator;
use Nextras\Dbal\Connection as NextrasConnection;
use Orisai\DbAudit\Auditor\InvalidDateMysqlAuditor;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Dbal\DibiAdapter;
use Orisai\DbAudit\Dbal\NextrasAdapter;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\MysqlConnectionConfig;
use Tests\Orisai\DbAudit\Helper\MysqlShortcuts;

final class InvalidDateMysqlAuditorTest extends TestCase
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
		$auditor = new InvalidDateMysqlAuditor($dbal);

		$key = $auditor::getKey();
		self::assertSame('invalid_date', $key);

		$db = 'invalid_date';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		self::assertEquals([], $auditor->analyse());

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

		self::assertEquals([], $auditor->analyse());

		//TODO - tady otestovat všechny nevalidní hodnoty
		//		- nastavit sql mód

		$report = $auditor->analyse();
		self::assertEquals([
			// TODO - tests
		], $report);
		self::assertEquals($report, $auditor->analyse());
	}

}

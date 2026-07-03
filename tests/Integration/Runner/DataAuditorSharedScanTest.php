<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Runner;

use Generator;
use Orisai\DbAudit\Auditor\EmptyColumnMysqlAuditor;
use Orisai\DbAudit\Auditor\InvalidDateMysqlAuditor;
use Orisai\DbAudit\Auditor\MixedEmptyValuesMysqlAuditor;
use Orisai\DbAudit\Auditor\NullableWithNoNullsMysqlAuditor;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Runner\Runner;
use Orisai\DbAudit\Schema\SchemaProvider;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\CountingDbalAdapter;
use Tests\Orisai\DbAudit\Helper\DbProvider;
use Tests\Orisai\DbAudit\Helper\MysqlShortcuts;

final class DataAuditorSharedScanTest extends TestCase
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
		$db = 'data_auditor_shared_scan';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `shared` (
	`id` int NOT NULL,
	`name` varchar(10) NULL,
	`created` datetime NULL
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `shared` (`id`, `name`, `created`) VALUES
(1, 'a', '2024-01-02 03:04:05')
SQL,
		);

		$counting = new CountingDbalAdapter($dbal);
		$schema = new SchemaProvider($counting);
		$runner = new Runner($schema, [
			new EmptyColumnMysqlAuditor($schema),
			new NullableWithNoNullsMysqlAuditor($schema),
			new MixedEmptyValuesMysqlAuditor($schema),
			new InvalidDateMysqlAuditor($schema),
		]);

		$runner->analyse();

		// Four data auditors, one data scan — the whole point of the shared profiler
		self::assertSame(1, $counting->getQueryCountContaining('FROM `shared`'));

		$shortcuts->dropDatabaseIfExists($db);
	}

}

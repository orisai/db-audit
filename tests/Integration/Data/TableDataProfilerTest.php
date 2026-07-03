<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Data;

use Generator;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Schema\ColumnCharsetClass;
use Orisai\DbAudit\Schema\SchemaCoordinator;
use Orisai\DbAudit\Schema\SchemaProvider;
use Orisai\DbAudit\Schema\SchemaRequest;
use Orisai\DbAudit\Schema\TableExclude;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\CountingDbalAdapter;
use Tests\Orisai\DbAudit\Helper\DbProvider;
use Tests\Orisai\DbAudit\Helper\MysqlShortcuts;

final class TableDataProfilerTest extends TestCase
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
		$db = 'table_data_profiler';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `profiled` (
	`id` int NOT NULL,
	`flag` tinyint NULL,
	`name` varchar(10) NULL,
	`created` datetime NULL
)
SQL,
		);

		$dbal->exec('SET @orisai_sql_mode = @@SESSION.sql_mode');
		$dbal->exec("SET SESSION sql_mode = ''");
		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `profiled` (`id`, `flag`, `name`, `created`) VALUES
(1, 0, 'a', '2024-01-01 00:00:00'),
(2, 1, '', '2024-00-01 00:00:00'),
(3, NULL, NULL, NULL)
SQL,
		);
		$dbal->exec('SET SESSION sql_mode = @orisai_sql_mode');

		$dbal->exec(
		/** @lang MySQL */
			'CREATE TABLE `excluded_1` (`a` int NULL)',
		);

		$dbal->exec(
		/** @lang MySQL */
			'CREATE VIEW `a_view` AS SELECT `id` FROM `profiled`',
		);

		$counting = new CountingDbalAdapter($dbal);
		$schema = new SchemaProvider($counting, (new TableExclude())->withPattern('^excluded_'));
		(new SchemaCoordinator($schema))->prime(
			[new SchemaRequest(ColumnCharsetClass::any(), null, false, false, true)],
		);

		$profiler = $schema->getDataProfiler();
		self::assertSame($profiler, $schema->getDataProfiler());

		$profile = $profiler->getProfile('profiled');
		self::assertNotNull($profile);
		self::assertSame(3, $profile['rowCount']);
		// NOT NULL column is derived, nullable columns are measured
		self::assertSame(
			['id' => 3, 'flag' => 2, 'name' => 2, 'created' => 2],
			$profile['nonNull'],
		);
		self::assertSame(['name' => 1], $profile['nonEmpty']);
		// id holds 1,2,3: only 1 is in {0,1}, so 2 rows are non-bool; flag holds only 0,1,NULL
		self::assertSame(['id' => 2, 'flag' => 0], $profile['nonBool']);
		self::assertSame(['created' => 1], $profile['invalidDate']);

		// Cached: second call must not add queries
		$before = $counting->getQueryCount();
		$profiler->getProfile('profiled');
		self::assertSame($before, $counting->getQueryCount());

		// Excluded and unknown tables are not profiled
		self::assertNull($profiler->getProfile('excluded_1'));
		self::assertNull($profiler->getProfile('a_view'));
		self::assertNull($profiler->getProfile('missing'));

		// Re-prime invalidates the cache
		(new SchemaCoordinator($schema))->prime(
			[new SchemaRequest(ColumnCharsetClass::any(), null, false, false, true)],
		);
		$afterPrime = $counting->getQueryCount();
		$profiler->getProfile('profiled');
		self::assertGreaterThan($afterPrime, $counting->getQueryCount());

		// Empty table profiles to zero counts
		$dbal->exec(
		/** @lang MySQL */
			'CREATE TABLE `empty_profiled` (`a` int NULL)',
		);
		(new SchemaCoordinator($schema))->prime(
			[new SchemaRequest(ColumnCharsetClass::any(), null, false, false, true)],
		);
		$empty = $profiler->getProfile('empty_profiled');
		self::assertNotNull($empty);
		self::assertSame(0, $empty['rowCount']);
		self::assertSame(['a' => 0], $empty['nonNull']);
		self::assertSame(['a' => 0], $empty['nonBool']);

		$shortcuts->dropDatabaseIfExists($db);
	}

}

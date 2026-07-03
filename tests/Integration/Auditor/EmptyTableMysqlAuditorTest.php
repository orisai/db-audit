<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Auditor;

use Generator;
use Orisai\DbAudit\Auditor\EmptyTableMysqlAuditor;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Report\TableViolationSource;
use Orisai\DbAudit\Report\Violation;
use Orisai\DbAudit\Schema\SchemaProvider;
use Orisai\DbAudit\Schema\TableExclude;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\AuditorRunner;
use Tests\Orisai\DbAudit\Helper\DbProvider;
use Tests\Orisai\DbAudit\Helper\MysqlShortcuts;

final class EmptyTableMysqlAuditorTest extends TestCase
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
		$auditor = new EmptyTableMysqlAuditor($schema);

		$key = 'empty_table';

		$db = 'empty_table';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		self::assertEquals([], AuditorRunner::analyse($schema, $auditor)->getViolations());

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `b` (
	`test` tinyint(1) unsigned NOT NULL
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `c` (
	`test` tinyint(1) unsigned NOT NULL
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `a` (
	`test` tinyint(1) unsigned NOT NULL
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `c` (`test`) VALUES
(1)
SQL,
		);

		self::assertEquals([
			new Violation(
				$key,
				'Table [a] is empty.',
				new TableViolationSource($db, null, 'a'),
			),
			new Violation(
				$key,
				'Table [b] is empty.',
				new TableViolationSource($db, null, 'b'),
			),
		], AuditorRunner::analyse($schema, $auditor)->getViolations());
	}

	/**
	 * @dataProvider provide
	 */
	public function testExactEmptinessIgnoresStaleRowEstimate(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$schema = new SchemaProvider($dbal);
		$auditor = new EmptyTableMysqlAuditor($schema);
		$key = 'empty_table';

		$db = 'empty_table_estimate';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		$dbal->exec(
		/** @lang MySQL */
			'CREATE TABLE `stale` (`id` int NOT NULL PRIMARY KEY) ENGINE=InnoDB',
		);
		$dbal->exec(
		/** @lang MySQL */
			'CREATE TABLE `genuinely_empty` (`id` int NOT NULL PRIMARY KEY) ENGINE=InnoDB',
		);

		// MySQL caches INFORMATION_SCHEMA.TABLES.TABLE_ROWS for information_schema_stats_expiry (default 1 day),
		// so reading it while `stale` is empty pins the estimate at 0 even after rows are inserted. The auditor
		// must verify emptiness exactly instead of trusting that stale estimate.
		$dbal->query(
		/** @lang MySQL */
			'SELECT TABLE_ROWS FROM INFORMATION_SCHEMA.TABLES'
			. ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $dbal->escapeString('stale'),
		);

		$dbal->exec(/** @lang MySQL */'INSERT INTO `stale` (`id`) VALUES (1)');

		self::assertEquals([
			new Violation(
				$key,
				'Table [genuinely_empty] is empty.',
				new TableViolationSource($db, null, 'genuinely_empty'),
			),
		], AuditorRunner::analyse($schema, $auditor)->getViolations());
	}

	/**
	 * @dataProvider provide
	 */
	public function testExcludedTableIsNotReported(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);

		$key = 'empty_table';

		$db = 'empty_table_excluded';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		$dbal->exec(
		/** @lang MySQL */
			'CREATE TABLE `excluded_1` (`id` int NOT NULL)',
		);

		$dbal->exec(
		/** @lang MySQL */
			'CREATE TABLE `kept` (`id` int NOT NULL)',
		);

		// An excluded table is empty too, but the provider's exclude must reach the table listing so it is
		// never probed nor reported.
		$excludingSchema = new SchemaProvider($dbal, (new TableExclude())->withPattern('^excluded_'));
		$excludingAuditor = new EmptyTableMysqlAuditor($excludingSchema);

		self::assertEquals([
			new Violation(
				$key,
				'Table [kept] is empty.',
				new TableViolationSource($db, null, 'kept'),
			),
		], AuditorRunner::analyse($excludingSchema, $excludingAuditor)->getViolations());
	}

}

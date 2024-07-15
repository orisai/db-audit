<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Schema;

use Generator;
use Orisai\DbAudit\Auditor\Latin1EncodingMysqlAuditor;
use Orisai\DbAudit\Auditor\OutdatedCollationMysqlAuditor;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Schema\SchemaCoordinator;
use Orisai\DbAudit\Schema\SchemaProvider;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\CountingDbalAdapter;
use Tests\Orisai\DbAudit\Helper\DbProvider;
use Tests\Orisai\DbAudit\Helper\MysqlShortcuts;

final class SchemaCoordinatorTest extends TestCase
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

	private function setUpDatabase(DbalAdapter $dbal, string $db): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createUtf8mb3CzechDatabase($db);
		$shortcuts->useDatabase($db);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `mix` (
	`id` int NOT NULL,
	`legacy` varchar(50) CHARACTER SET latin1 NULL,
	`old_unicode` varchar(50) CHARACTER SET utf8mb3 NULL,
	`modern` varchar(50) CHARACTER SET utf8mb4 NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
	}

	/**
	 * @dataProvider provide
	 */
	public function testUnionFetchedOnce(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'schema_coordinator_union';
		$this->setUpDatabase($dbal, $db);

		$counting = new CountingDbalAdapter($dbal);
		$provider = new SchemaProvider($counting);

		// The two auditors declare overlapping needs (collation wants every column of its in-scope tables,
		// encoding wants single-byte columns) over the same database; the coordinator unions them into one
		// fetch whose predicate is `1 = 1 OR (CHARACTER_SET_NAME IN <single-byte>)`.
		$collation = new OutdatedCollationMysqlAuditor($counting, null, $provider);
		$encoding = new Latin1EncodingMysqlAuditor($counting, $provider);

		$coordinator = new SchemaCoordinator($provider);
		$coordinator->prime([$collation->getSchemaRequest(), $encoding->getSchemaRequest()]);

		// The heavy COLUMNS and STATISTICS tables are each read exactly once for the whole set.
		self::assertSame(1, $counting->getQueryCountContaining('INFORMATION_SCHEMA.COLUMNS'));
		self::assertSame(1, $counting->getQueryCountContaining('INFORMATION_SCHEMA.STATISTICS'));

		$names = [];
		foreach ($provider->getColumns() as $column) {
			if ($column['TABLE_NAME'] === 'mix') {
				$names[] = $column['COLUMN_NAME'];
			}
		}

		// The collation request (any, unscoped) pulls every column of the in-scope table, so the union holds
		// the utf8mb3, latin1 and utf8mb4 columns AND the charset-less int — the collation auditor needs them
		// all for cross-column index byte math and column reconstruction.
		self::assertContains('old_unicode', $names);
		self::assertContains('legacy', $names);
		self::assertContains('modern', $names);
		self::assertContains('id', $names);
	}

	/**
	 * @dataProvider provide
	 */
	public function testInjectedProviderNotReprimedByAuditor(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'schema_coordinator_injected';
		$this->setUpDatabase($dbal, $db);

		$counting = new CountingDbalAdapter($dbal);
		$provider = new SchemaProvider($counting);

		$collation = new OutdatedCollationMysqlAuditor($counting, null, $provider);
		$encoding = new Latin1EncodingMysqlAuditor($counting, $provider);

		$coordinator = new SchemaCoordinator($provider);
		$coordinator->prime([$collation->getSchemaRequest(), $encoding->getSchemaRequest()]);

		$collation->analyse();
		$encoding->analyse();

		// Running both injected auditors reuses the coordinator's single primed fetch — no extra COLUMNS query.
		self::assertSame(1, $counting->getQueryCountContaining('INFORMATION_SCHEMA.COLUMNS'));
	}

}

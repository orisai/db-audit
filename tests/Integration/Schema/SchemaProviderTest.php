<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Schema;

use Generator;
use Orisai\DbAudit\Auditor\Latin1EncodingMysqlAuditor;
use Orisai\DbAudit\Auditor\OutdatedCollationMysqlAuditor;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Schema\ColumnCharsetClass;
use Orisai\DbAudit\Schema\SchemaProvider;
use Orisai\DbAudit\Schema\SchemaRequest;
use Orisai\DbAudit\Schema\TableExclude;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\CountingDbalAdapter;
use Tests\Orisai\DbAudit\Helper\DbProvider;
use Tests\Orisai\DbAudit\Helper\MysqlShortcuts;
use function sort;

final class SchemaProviderTest extends TestCase
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
CREATE TABLE `parent` (
	`id` int NOT NULL,
	`code` varchar(50) CHARACTER SET latin1 NOT NULL,
	PRIMARY KEY (`id`),
	UNIQUE KEY `uq_code` (`code`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `child` (
	`id` int NOT NULL,
	`parent_id` int NOT NULL,
	`note` text NULL,
	PRIMARY KEY (`id`),
	KEY `ix_parent` (`parent_id`),
	CONSTRAINT `fk_child_parent` FOREIGN KEY (`parent_id`) REFERENCES `parent` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
		);
	}

	/**
	 * @dataProvider provide
	 */
	public function testGetters(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'schema_provider_getters';
		$this->setUpDatabase($dbal, $db);

		$provider = new SchemaProvider($dbal);

		self::assertSame($dbal, $provider->getDbal());

		$tables = [];
		foreach ($provider->getTables() as $table) {
			$tables[$table['TABLE_NAME']] = $table;
		}

		self::assertArrayHasKey('parent', $tables);
		self::assertArrayHasKey('child', $tables);
		self::assertSame('InnoDB', $tables['parent']['ENGINE']);
		self::assertNotNull($tables['parent']['TABLE_COLLATION']);

		$columnNames = [];
		foreach ($provider->getColumns() as $column) {
			$columnNames[$column['TABLE_NAME']][] = $column['COLUMN_NAME'];
		}

		// Every column is returned, including non-text columns (id, parent_id) — the snapshot is unfiltered.
		self::assertSame(['id', 'code'], $columnNames['parent']);
		self::assertSame(['id', 'parent_id', 'note'], $columnNames['child']);

		$codeColumn = null;
		foreach ($provider->getColumns() as $column) {
			if ($column['TABLE_NAME'] === 'parent' && $column['COLUMN_NAME'] === 'code') {
				$codeColumn = $column;
			}
		}

		self::assertNotNull($codeColumn);
		self::assertSame('latin1', $codeColumn['CHARACTER_SET_NAME']);
		self::assertSame(50, $codeColumn['CHARACTER_MAXIMUM_LENGTH']);

		$indexes = [];
		foreach ($provider->getStatistics() as $stat) {
			$indexes[$stat['TABLE_NAME']][$stat['INDEX_NAME']] = $stat['NON_UNIQUE'];
		}

		self::assertSame(0, $indexes['parent']['PRIMARY']);
		self::assertSame(0, $indexes['parent']['uq_code']);
		self::assertSame(1, $indexes['child']['ix_parent']);

		$foreignKeys = $provider->getForeignKeys();
		self::assertCount(1, $foreignKeys);
		self::assertSame('fk_child_parent', $foreignKeys[0]['CONSTRAINT_NAME']);
		self::assertSame('child', $foreignKeys[0]['TABLE_NAME']);
		self::assertSame('parent', $foreignKeys[0]['REFERENCED_TABLE_NAME']);
		self::assertSame('parent_id', $foreignKeys[0]['COLUMN_NAME']);
		self::assertSame('id', $foreignKeys[0]['REFERENCED_COLUMN_NAME']);
		self::assertSame('CASCADE', $foreignKeys[0]['DELETE_RULE']);

		$maxlen = [];
		foreach ($provider->getCharacterSets() as $charset) {
			$maxlen[$charset['CHARACTER_SET_NAME']] = $charset['MAXLEN'];
		}

		self::assertSame(1, $maxlen['latin1']);
		self::assertSame(4, $maxlen['utf8mb4']);

		$collationCharsets = [];
		foreach ($provider->getCollations() as $collation) {
			$collationCharsets[$collation['COLLATION_NAME']] = $collation['CHARACTER_SET_NAME'];
		}

		self::assertArrayHasKey('latin1_swedish_ci', $collationCharsets);
		self::assertSame('latin1', $collationCharsets['latin1_swedish_ci']);

		$default = $provider->getDatabaseDefault();
		self::assertSame($db, $default['name']);
		self::assertSame('utf8mb3', $default['charset']);
		self::assertSame('utf8mb3_czech_ci', $default['collation']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testStoredObjectGetters(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'schema_provider_stored';
		$shortcuts = new MysqlShortcuts($dbal);
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createUtf8mb3CzechDatabase($db);
		$shortcuts->useDatabase($db);

		$dbal->exec(
		/** @lang MySQL */
			'CREATE TABLE `t` (`id` int NOT NULL, `title` varchar(50) NOT NULL)'
			. ' DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci',
		);
		$dbal->exec(
		/** @lang MySQL */
			'CREATE VIEW `v` AS SELECT `id`, `title` COLLATE utf8mb3_czech_ci AS `tt` FROM `t`',
		);
		$dbal->exec(
		/** @lang MySQL */
			'CREATE PROCEDURE `p` () BEGIN SELECT CONVERT(`title` USING utf8mb3) FROM `t`; END',
		);
		$dbal->exec(
		/** @lang MySQL */
			'CREATE TRIGGER `trg` BEFORE INSERT ON `t` FOR EACH ROW'
			. ' SET NEW.`title` = CONVERT(NEW.`title` USING utf8mb3)',
		);

		$provider = new SchemaProvider($dbal);

		$views = [];
		foreach ($provider->getViews() as $view) {
			$views[$view['TABLE_NAME']] = $view['VIEW_DEFINITION'];
		}

		self::assertArrayHasKey('v', $views);
		self::assertStringContainsString('utf8mb3_czech_ci', $views['v']);

		$routines = [];
		foreach ($provider->getRoutines() as $routine) {
			$routines[$routine['ROUTINE_NAME']] = $routine;
		}

		self::assertArrayHasKey('p', $routines);
		self::assertSame('PROCEDURE', $routines['p']['ROUTINE_TYPE']);
		self::assertStringContainsString('utf8mb3', $routines['p']['ROUTINE_DEFINITION']);

		$triggers = [];
		foreach ($provider->getTriggers() as $trigger) {
			$triggers[$trigger['TRIGGER_NAME']] = $trigger['ACTION_STATEMENT'];
		}

		self::assertArrayHasKey('trg', $triggers);
		self::assertStringContainsString('utf8mb3', $triggers['trg']);

		// EVENTS is unfiltered and current-DB scoped; a fresh database has none, so the getter is empty.
		self::assertSame([], $provider->getEvents());
	}

	/**
	 * @dataProvider provide
	 */
	public function testCaching(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'schema_provider_caching';
		$this->setUpDatabase($dbal, $db);

		$counting = new CountingDbalAdapter($dbal);
		$provider = new SchemaProvider($counting);

		$first = $provider->getColumns();
		$afterFirst = $counting->getQueryCount();
		$second = $provider->getColumns();
		$afterSecond = $counting->getQueryCount();

		// The second call serves the cached snapshot — no further query is issued.
		self::assertSame($first, $second);
		self::assertSame(1, $afterFirst);
		self::assertSame($afterFirst, $afterSecond);

		// Each other getter likewise queries the minimum across two calls; getForeignKeys() is split into two
		// queries (KEY_COLUMN_USAGE columns + REFERENTIAL_CONSTRAINTS rules) merged in PHP, both cached after
		// the first call.
		$before = $counting->getQueryCount();
		$provider->getTables();
		$provider->getTables();
		$provider->getStatistics();
		$provider->getStatistics();
		$provider->getForeignKeys();
		$provider->getForeignKeys();
		$provider->getCharacterSets();
		$provider->getCharacterSets();
		$provider->getCollations();
		$provider->getCollations();
		$provider->getDatabaseDefault();
		$provider->getDatabaseDefault();

		// Six distinct getters, each fetched once despite two calls apiece; getForeignKeys() costs two queries,
		// the other five one each.
		self::assertSame(7, $counting->getQueryCount() - $before);
	}

	/**
	 * @dataProvider provide
	 */
	public function testByTableAccessors(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'schema_provider_by_table';
		$this->setUpDatabase($dbal, $db);

		$provider = new SchemaProvider($dbal);

		$columnsByTable = $provider->getColumnsByTable();
		self::assertArrayHasKey('parent', $columnsByTable);
		self::assertArrayHasKey('child', $columnsByTable);

		$parentColumns = [];
		foreach ($columnsByTable['parent'] as $column) {
			$parentColumns[] = $column['COLUMN_NAME'];
		}

		// ORDINAL_POSITION order is preserved within each table's bucket.
		self::assertSame(['id', 'code'], $parentColumns);

		$childColumns = [];
		foreach ($columnsByTable['child'] as $childColumn) {
			$childColumns[] = $childColumn['COLUMN_NAME'];
		}

		self::assertSame(['id', 'parent_id', 'note'], $childColumns);

		$statisticsByTable = $provider->getStatisticsByTable();
		$parentIndexes = [];
		foreach ($statisticsByTable['parent'] as $stat) {
			$parentIndexes[$stat['INDEX_NAME']] = $stat['NON_UNIQUE'];
		}

		self::assertSame(0, $parentIndexes['PRIMARY']);
		self::assertSame(0, $parentIndexes['uq_code']);

		$childIndexes = [];
		foreach ($statisticsByTable['child'] as $childStat) {
			$childIndexes[$childStat['INDEX_NAME']] = $childStat['NON_UNIQUE'];
		}

		self::assertSame(1, $childIndexes['ix_parent']);

		// The FK relationship is reachable through the graph from either endpoint table.
		$graph = $provider->getForeignKeyGraph();
		$touchingChild = $graph->getTouching(['child' => true]);
		self::assertCount(1, $touchingChild);
		self::assertSame('fk_child_parent', $touchingChild[0]->name);
		self::assertSame('parent', $touchingChild[0]->referencedTable);

		$touchingParent = $graph->getTouching(['parent' => true]);
		self::assertCount(1, $touchingParent);
		self::assertSame('fk_child_parent', $touchingParent[0]->name);
	}

	/**
	 * @dataProvider provide
	 */
	public function testByTableAccessorsCached(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'schema_provider_by_table_cached';
		$this->setUpDatabase($dbal, $db);

		$counting = new CountingDbalAdapter($dbal);
		$provider = new SchemaProvider($counting);

		// The columns by-table index is built from the flat snapshot: one COLUMNS query total, with the
		// flat getter and both by-table calls served from the same cache.
		$provider->getColumns();
		$provider->getColumnsByTable();
		$provider->getColumnsByTable();
		self::assertSame(1, $counting->getQueryCountContaining('INFORMATION_SCHEMA.COLUMNS'));

		// The statistics by-table index issues no query beyond the single flat STATISTICS fetch.
		$provider->getStatistics();
		$provider->getStatisticsByTable();
		$provider->getStatisticsByTable();
		self::assertSame(1, $counting->getQueryCountContaining('INFORMATION_SCHEMA.STATISTICS'));

		// The FK graph reuses the one flat KEY_COLUMN_USAGE fetch.
		$provider->getForeignKeys();
		$provider->getForeignKeyGraph();
		$provider->getForeignKeyGraph();
		self::assertSame(1, $counting->getQueryCountContaining('INFORMATION_SCHEMA.KEY_COLUMN_USAGE'));

		// A fresh provider whose by-table accessor is called FIRST still triggers exactly one flat fetch
		// (no extra query), proving the index is derived from the cached flat list rather than its own query.
		$lazyCounting = new CountingDbalAdapter($dbal);
		$lazyProvider = new SchemaProvider($lazyCounting);
		$lazyProvider->getColumnsByTable();
		self::assertSame(1, $lazyCounting->getQueryCountContaining('INFORMATION_SCHEMA.COLUMNS'));
	}

	/**
	 * @dataProvider provide
	 */
	public function testSharedAcrossAuditors(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'schema_provider_sharing';
		$this->setUpDatabase($dbal, $db);

		$counting = new CountingDbalAdapter($dbal);

		$shared = new SchemaProvider($counting);
		$collation = new OutdatedCollationMysqlAuditor($shared, null);
		$latin1 = new Latin1EncodingMysqlAuditor($shared);

		$collation->analyse();
		$latin1->analyse();

		// Both auditors read columns through the one shared snapshot: the COLUMNS query runs once total.
		self::assertSame(1, $counting->getQueryCountContaining('INFORMATION_SCHEMA.COLUMNS'));
	}

	private function setUpMixDatabase(DbalAdapter $dbal, string $db): void
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
	`lat` varchar(50) CHARACTER SET latin1 NULL,
	`u3` varchar(50) CHARACTER SET utf8mb3 NULL,
	`u4` varchar(50) CHARACTER SET utf8mb4 NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
	}

	/**
	 * @return list<string>
	 */
	private function columnNamesOf(SchemaProvider $provider, string $table): array
	{
		$names = [];
		foreach ($provider->getColumns() as $column) {
			if ($column['TABLE_NAME'] === $table) {
				$names[] = $column['COLUMN_NAME'];
			}
		}

		return $names;
	}

	/**
	 * @dataProvider provide
	 */
	public function testPrimeColumnsNonUtf8mb4(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'schema_provider_prime_nonutf8mb4';
		$this->setUpMixDatabase($dbal, $db);

		$provider = new SchemaProvider($dbal);
		self::assertFalse($provider->isColumnsPrimed());

		$provider->primeColumns([new SchemaRequest(ColumnCharsetClass::nonUtf8mb4())]);
		self::assertTrue($provider->isColumnsPrimed());

		$names = $this->columnNamesOf($provider, 'mix');
		// non-utf8mb4 keeps the latin1 and utf8mb3 columns but drops the utf8mb4 one and the charset-less int.
		self::assertContains('lat', $names);
		self::assertContains('u3', $names);
		self::assertNotContains('u4', $names);
		self::assertNotContains('id', $names);
	}

	/**
	 * @dataProvider provide
	 */
	public function testPrimeColumnsSingleByte(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'schema_provider_prime_singlebyte';
		$this->setUpMixDatabase($dbal, $db);

		$provider = new SchemaProvider($dbal);
		$provider->primeColumns([new SchemaRequest(ColumnCharsetClass::singleByte())]);

		$names = $this->columnNamesOf($provider, 'mix');
		// Only the single-byte (latin1) column survives; utf8mb3 (MAXLEN 3) and utf8mb4 are excluded.
		self::assertContains('lat', $names);
		self::assertNotContains('u3', $names);
		self::assertNotContains('u4', $names);
		self::assertNotContains('id', $names);
	}

	/**
	 * @dataProvider provide
	 */
	public function testPrimeColumnsAny(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'schema_provider_prime_any';
		$this->setUpMixDatabase($dbal, $db);

		$provider = new SchemaProvider($dbal);
		$provider->primeColumns([new SchemaRequest(ColumnCharsetClass::any())]);

		$names = $this->columnNamesOf($provider, 'mix');
		// `any` carries no charset predicate: every column is returned, including the charset-less int.
		self::assertContains('lat', $names);
		self::assertContains('u3', $names);
		self::assertContains('u4', $names);
		self::assertContains('id', $names);
	}

	/**
	 * @dataProvider provide
	 */
	public function testPrimeColumnsEmptyRequestsPrimeToEmpty(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'schema_provider_prime_empty';
		$this->setUpMixDatabase($dbal, $db);

		$counting = new CountingDbalAdapter($dbal);
		$provider = new SchemaProvider($counting);

		$provider->primeColumns([]);
		$provider->primeStatistics([]);

		self::assertTrue($provider->isColumnsPrimed());
		self::assertTrue($provider->isStatisticsPrimed());
		self::assertSame([], $provider->getColumns());
		self::assertSame([], $provider->getStatistics());
		// No query is issued for an empty request set.
		self::assertSame(0, $counting->getQueryCountContaining('INFORMATION_SCHEMA.COLUMNS'));
		self::assertSame(0, $counting->getQueryCountContaining('INFORMATION_SCHEMA.STATISTICS'));
	}

	/**
	 * @dataProvider provide
	 */
	public function testPrimeColumnsTableScopeHonoursExcludes(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'schema_provider_prime_scope';
		// parent (latin1 `code`) is excluded; child (`note`) stays. No FK pull-in, so parent is absent.
		$this->setUpDatabase($dbal, $db);

		$provider = new SchemaProvider($dbal);
		$provider->primeColumns([
			new SchemaRequest(ColumnCharsetClass::any(), (new TableExclude())->withPattern('^parent$')),
		]);

		self::assertSame([], $this->columnNamesOf($provider, 'parent'));
		self::assertContains('note', $this->columnNamesOf($provider, 'child'));
	}

	/**
	 * @dataProvider provide
	 */
	public function testPrimeColumnsIncludeForeignKeyRelated(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'schema_provider_prime_fk';
		// `child` references `parent`; excluding `parent` but asking for FK-related tables must pull its
		// columns back in (so a boundary FK conversion can see both ends).
		$this->setUpDatabase($dbal, $db);

		$without = new SchemaProvider($dbal);
		$without->primeColumns([
			new SchemaRequest(ColumnCharsetClass::any(), (new TableExclude())->withPattern('^parent$'), false),
		]);
		self::assertSame([], $this->columnNamesOf($without, 'parent'));

		$with = new SchemaProvider($dbal);
		$with->primeColumns([
			new SchemaRequest(ColumnCharsetClass::any(), (new TableExclude())->withPattern('^parent$'), true),
		]);
		self::assertContains('code', $this->columnNamesOf($with, 'parent'));
	}

	/**
	 * @dataProvider provide
	 */
	public function testPrimeStatisticsScopedToNeedingRequests(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'schema_provider_prime_stats';
		$this->setUpDatabase($dbal, $db);

		$counting = new CountingDbalAdapter($dbal);

		// No request needs statistics: primed empty, no query.
		$none = new SchemaProvider($counting);
		$none->primeStatistics([new SchemaRequest(ColumnCharsetClass::singleByte(), null, false, false)]);
		self::assertSame([], $none->getStatistics());
		self::assertSame(0, $counting->getQueryCountContaining('INFORMATION_SCHEMA.STATISTICS'));

		// A needing request scoped to `parent` only fetches that table's index rows.
		$scoped = new SchemaProvider($dbal);
		$scoped->primeStatistics([
			new SchemaRequest(ColumnCharsetClass::any(), (new TableExclude())->withPattern('^child$'), false, true),
		]);
		$tables = [];
		foreach ($scoped->getStatistics() as $stat) {
			$tables[$stat['TABLE_NAME']] = true;
		}

		self::assertArrayHasKey('parent', $tables);
		self::assertArrayNotHasKey('child', $tables);
	}

	/**
	 * @dataProvider provide
	 */
	public function testGetTableNames(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'schema_provider_table_names';
		$this->setUpDatabase($dbal, $db);

		$provider = new SchemaProvider($dbal);

		$names = $provider->getTableNames();
		sort($names);

		self::assertSame(['child', 'parent'], $names);
	}

	private function setUpCompositeForeignKeyDatabase(DbalAdapter $dbal, string $db): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createUtf8mb3CzechDatabase($db);
		$shortcuts->useDatabase($db);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `region` (
	`a` int NOT NULL,
	`b` int NOT NULL,
	PRIMARY KEY (`a`, `b`)
) ENGINE=InnoDB
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `district` (
	`id` int NOT NULL,
	`ra` int NULL,
	`rb` int NULL,
	PRIMARY KEY (`id`),
	KEY `ix_region` (`ra`, `rb`),
	CONSTRAINT `fk_district_region` FOREIGN KEY (`ra`, `rb`) REFERENCES `region` (`a`, `b`)
		ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB
SQL,
		);
	}

	/**
	 * @dataProvider provide
	 */
	public function testForeignKeysSplitFetchMatchesShape(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'schema_provider_fk_split';
		$this->setUpCompositeForeignKeyDatabase($dbal, $db);

		$provider = new SchemaProvider($dbal);

		// The split KCU + REFERENTIAL_CONSTRAINTS fetch must return the same flat per-column shape as the old
		// JOIN: one row per referencing column in ORDINAL_POSITION order, with the per-constraint UPDATE_RULE /
		// DELETE_RULE merged onto every column row.
		$foreignKeys = $provider->getForeignKeys();
		self::assertCount(2, $foreignKeys);

		$first = $foreignKeys[0];
		self::assertSame('fk_district_region', $first['CONSTRAINT_NAME']);
		self::assertSame('district', $first['TABLE_NAME']);
		self::assertSame('ra', $first['COLUMN_NAME']);
		self::assertSame(1, $first['ORDINAL_POSITION']);
		self::assertSame('region', $first['REFERENCED_TABLE_NAME']);
		self::assertSame('a', $first['REFERENCED_COLUMN_NAME']);
		self::assertSame('CASCADE', $first['UPDATE_RULE']);
		self::assertSame('SET NULL', $first['DELETE_RULE']);

		$second = $foreignKeys[1];
		self::assertSame('fk_district_region', $second['CONSTRAINT_NAME']);
		self::assertSame('rb', $second['COLUMN_NAME']);
		self::assertSame(2, $second['ORDINAL_POSITION']);
		self::assertSame('b', $second['REFERENCED_COLUMN_NAME']);
		// The referential actions are attached to every column row of the constraint, not just the first.
		self::assertSame('CASCADE', $second['UPDATE_RULE']);
		self::assertSame('SET NULL', $second['DELETE_RULE']);

		// The graph built from the merged rows preserves composite column order.
		$constraints = $provider->getForeignKeyGraph()->getTouching(['district' => true]);
		self::assertCount(1, $constraints);
		self::assertSame(['ra', 'rb'], $constraints[0]->columns);
		self::assertSame(['a', 'b'], $constraints[0]->referencedColumns);
	}

	/**
	 * @dataProvider provide
	 */
	public function testPrimeTablesAndForeignKeysScoped(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'schema_provider_prime_tables_fk';
		$this->setUpDatabase($dbal, $db);

		$counting = new CountingDbalAdapter($dbal);
		$provider = new SchemaProvider($counting);

		// Exclude `parent` but ask for FK-related tables: table metadata is fetched only for the in-scope
		// `child`, while the boundary FK child->parent is still pulled in (its referencing side is in scope).
		$provider->primeTablesAndForeignKeys([
			new SchemaRequest(
				ColumnCharsetClass::any(),
				(new TableExclude())->withPattern('^parent$'),
				true,
				true,
				true,
			),
		]);

		self::assertTrue($provider->isTablesPrimed());
		self::assertTrue($provider->isForeignKeysPrimed());

		$tableNames = [];
		foreach ($provider->getTables() as $table) {
			$tableNames[$table['TABLE_NAME']] = true;
		}

		// Full metadata is scoped to the included table only; the excluded `parent` is not opened.
		self::assertArrayHasKey('child', $tableNames);
		self::assertArrayNotHasKey('parent', $tableNames);

		// The boundary FK is present (so getTouching keeps pulling the excluded parent back in).
		$foreignKeys = $provider->getForeignKeys();
		self::assertCount(1, $foreignKeys);
		self::assertSame('fk_child_parent', $foreignKeys[0]['CONSTRAINT_NAME']);
		$touching = $provider->getForeignKeyGraph()->getTouching(['child' => true]);
		self::assertCount(1, $touching);
		self::assertSame('parent', $touching[0]->referencedTable);

		// Full table metadata, KCU columns and REFERENTIAL_CONSTRAINTS rules are each fetched exactly once;
		// accessing the primed data issues no further query.
		self::assertSame(1, $counting->getQueryCountContaining('ROW_FORMAT'));
		self::assertSame(1, $counting->getQueryCountContaining('INFORMATION_SCHEMA.KEY_COLUMN_USAGE'));
		self::assertSame(1, $counting->getQueryCountContaining('INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS'));
	}

	/**
	 * @dataProvider provide
	 */
	public function testPrimeTablesAndForeignKeysSkippedWhenNotNeeded(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'schema_provider_prime_tables_fk_skip';
		$this->setUpDatabase($dbal, $db);

		$counting = new CountingDbalAdapter($dbal);
		$provider = new SchemaProvider($counting);

		// A request that needs neither table metadata nor FK-related expansion (e.g. the encoding auditor's
		// whole-database column request) primes neither: tables and FKs stay unprimed so their lazy whole-DB
		// fallback remains intact and returns the real data instead of silently empty results.
		$provider->primeTablesAndForeignKeys([
			new SchemaRequest(ColumnCharsetClass::singleByte(), new TableExclude(), false, false, false),
		]);

		// No table-metadata or FK queries during prime.
		self::assertFalse($provider->isTablesPrimed());
		self::assertFalse($provider->isForeignKeysPrimed());
		self::assertSame(0, $counting->getQueryCountContaining('ROW_FORMAT'));
		self::assertSame(0, $counting->getQueryCountContaining('INFORMATION_SCHEMA.KEY_COLUMN_USAGE'));
		self::assertSame(0, $counting->getQueryCountContaining('INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS'));

		// The lazy fallback returns the real tables, not empty.
		$tableNames = [];
		foreach ($provider->getTables() as $table) {
			$tableNames[] = $table['TABLE_NAME'];
		}

		self::assertContains('parent', $tableNames);
		self::assertContains('child', $tableNames);
	}

	private function setUpGlobalExcludeDatabase(DbalAdapter $dbal, string $db): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createUtf8mb3CzechDatabase($db);
		$shortcuts->useDatabase($db);

		// An excluded `_<int>` temp table referenced by a normal one, plus an unrelated normal table.
		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `_10520` (
	`id` int NOT NULL,
	`code` varchar(50) NOT NULL,
	PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `normal` (
	`id` int NOT NULL,
	`ref_id` int NOT NULL,
	`note` varchar(50) NULL,
	PRIMARY KEY (`id`),
	KEY `ix_ref` (`ref_id`),
	CONSTRAINT `fk_normal_ref` FOREIGN KEY (`ref_id`) REFERENCES `_10520` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `keep` (
	`id` int NOT NULL,
	`label` varchar(50) NULL,
	PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
	}

	/**
	 * @dataProvider provide
	 */
	public function testGlobalExcludeRemovesTableFromScopedColumns(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'schema_provider_global_exclude_columns';
		$this->setUpGlobalExcludeDatabase($dbal, $db);

		// The provider carries the global exclude; the request adds nothing (empty per-request exclude).
		$provider = new SchemaProvider($dbal, (new TableExclude())->withPattern('^_[0-9]+$'));
		$provider->primeColumns([new SchemaRequest(ColumnCharsetClass::any())]);

		// The excluded `_10520` is absent from the scoped snapshot; the normal tables survive.
		self::assertSame([], $this->columnNamesOf($provider, '_10520'));
		self::assertContains('note', $this->columnNamesOf($provider, 'normal'));
		self::assertContains('label', $this->columnNamesOf($provider, 'keep'));
	}

	/**
	 * @dataProvider provide
	 */
	public function testGlobalExcludeForeignKeyBoundaryReincludesExcludedTable(
		DbalAdapter $dbal,
		DatabaseEngine $engine
	): void
	{
		$db = 'schema_provider_global_exclude_fk';
		$this->setUpGlobalExcludeDatabase($dbal, $db);

		// Without FK-related expansion the globally-excluded `_10520` stays out.
		$without = new SchemaProvider($dbal, (new TableExclude())->withPattern('^_[0-9]+$'));
		$without->primeColumns([new SchemaRequest(ColumnCharsetClass::any(), null, false)]);
		self::assertSame([], $this->columnNamesOf($without, '_10520'));

		// With it, the FK from the non-excluded `normal` re-includes the excluded `_10520`.
		$with = new SchemaProvider($dbal, (new TableExclude())->withPattern('^_[0-9]+$'));
		$with->primeColumns([new SchemaRequest(ColumnCharsetClass::any(), null, true)]);
		self::assertContains('code', $this->columnNamesOf($with, '_10520'));
		self::assertContains('note', $this->columnNamesOf($with, 'normal'));
	}

	/**
	 * @dataProvider provide
	 */
	public function testGlobalExcludeAppliedToScopedStatistics(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'schema_provider_global_exclude_stats';
		$this->setUpGlobalExcludeDatabase($dbal, $db);

		$provider = new SchemaProvider($dbal, (new TableExclude())->withPattern('^_[0-9]+$'));
		$provider->primeStatistics([new SchemaRequest(ColumnCharsetClass::any(), null, false, true)]);

		$tables = [];
		foreach ($provider->getStatistics() as $stat) {
			$tables[$stat['TABLE_NAME']] = true;
		}

		self::assertArrayHasKey('normal', $tables);
		self::assertArrayHasKey('keep', $tables);
		self::assertArrayNotHasKey('_10520', $tables);
	}

	/**
	 * @dataProvider provide
	 */
	public function testGlobalExcludeAppliedToScopedTablesKeepsBoundaryForeignKey(
		DbalAdapter $dbal,
		DatabaseEngine $engine
	): void
	{
		$db = 'schema_provider_global_exclude_tables';
		$this->setUpGlobalExcludeDatabase($dbal, $db);

		$provider = new SchemaProvider($dbal, (new TableExclude())->withPattern('^_[0-9]+$'));
		$provider->primeTablesAndForeignKeys([
			new SchemaRequest(ColumnCharsetClass::any(), null, true, false, true),
		]);

		$tableNames = [];
		foreach ($provider->getTables() as $table) {
			$tableNames[$table['TABLE_NAME']] = true;
		}

		// Table metadata is scoped by the global exclude; the FK boundary does NOT pull `_10520` metadata in.
		self::assertArrayHasKey('normal', $tableNames);
		self::assertArrayHasKey('keep', $tableNames);
		self::assertArrayNotHasKey('_10520', $tableNames);

		// The boundary FK is still fetched (its referencing side `normal` is in scope), so the graph keeps it.
		$foreignKeys = $provider->getForeignKeys();
		self::assertCount(1, $foreignKeys);
		self::assertSame('fk_normal_ref', $foreignKeys[0]['CONSTRAINT_NAME']);
		self::assertSame('_10520', $foreignKeys[0]['REFERENCED_TABLE_NAME']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testGlobalExcludeMergesWithRequestExclude(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'schema_provider_global_exclude_merge';
		$this->setUpGlobalExcludeDatabase($dbal, $db);

		// Global exclude drops `_10520`; the per-request exclude additionally drops `normal`; `keep` survives.
		$provider = new SchemaProvider($dbal, (new TableExclude())->withPattern('^_[0-9]+$'));
		$provider->primeColumns([
			new SchemaRequest(ColumnCharsetClass::any(), (new TableExclude())->withPattern('^normal$')),
		]);

		self::assertSame([], $this->columnNamesOf($provider, '_10520'));
		self::assertSame([], $this->columnNamesOf($provider, 'normal'));
		self::assertContains('label', $this->columnNamesOf($provider, 'keep'));
	}

}

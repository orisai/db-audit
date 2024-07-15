<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Schema;

use Orisai\DbAudit\Dbal\DbalAdapter;
use function array_keys;

final class SchemaProvider
{

	private const ColumnsSelect = 'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, DATA_TYPE,'
		. ' CHARACTER_SET_NAME, COLLATION_NAME, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_COMMENT,'
		. ' GENERATION_EXPRESSION, CHARACTER_MAXIMUM_LENGTH, ORDINAL_POSITION'
		. ' FROM INFORMATION_SCHEMA.COLUMNS';

	private const ColumnsOrder = ' ORDER BY TABLE_NAME, ORDINAL_POSITION';

	private const StatisticsSelect = 'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX,'
		. ' COLUMN_NAME, SUB_PART'
		. ' FROM INFORMATION_SCHEMA.STATISTICS';

	private const StatisticsOrder = ' ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX';

	private DbalAdapter $dbal;

	/** @var list<string>|null */
	private ?array $tableNames = null;

	/** @var list<array<string, mixed>>|null */
	private ?array $tables = null;

	/** @var list<array<string, mixed>>|null */
	private ?array $columns = null;

	/** @var list<array<string, mixed>>|null */
	private ?array $statistics = null;

	/** @var list<array<string, mixed>>|null */
	private ?array $foreignKeys = null;

	/** @var array<string, list<array<string, mixed>>>|null */
	private ?array $columnsByTable = null;

	/** @var array<string, list<array<string, mixed>>>|null */
	private ?array $statisticsByTable = null;

	private ?ForeignKeyGraph $foreignKeyGraph = null;

	/** @var list<array<string, mixed>>|null */
	private ?array $characterSets = null;

	/** @var list<array<string, mixed>>|null */
	private ?array $collations = null;

	/** @var array{name: string, charset: string, collation: string}|null */
	private ?array $databaseDefault = null;

	/** @var list<array<string, mixed>>|null */
	private ?array $views = null;

	/** @var list<array<string, mixed>>|null */
	private ?array $routines = null;

	/** @var list<array<string, mixed>>|null */
	private ?array $triggers = null;

	/** @var list<array<string, mixed>>|null */
	private ?array $events = null;

	public function __construct(DbalAdapter $dbal)
	{
		$this->dbal = $dbal;
	}

	public function getDbal(): DbalAdapter
	{
		return $this->dbal;
	}

	/**
	 * The cheap names-only listing used to compute the in-scope table set without opening every table's full
	 * metadata (ENGINE/ROW_FORMAT/TABLE_COLLATION), which is expensive on databases with very many tables.
	 *
	 * @return list<string>
	 */
	public function getTableNames(): array
	{
		if ($this->tableNames === null) {
			$rows = $this->dbal->query(
				<<<'SQL'
SELECT TABLE_NAME
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
ORDER BY TABLE_NAME
SQL,
			);

			$names = [];
			foreach ($rows as $row) {
				$names[] = (string) $row['TABLE_NAME'];
			}

			$this->tableNames = $names;
		}

		return $this->tableNames;
	}

	/**
	 * @return list<array{TABLE_NAME: string, ENGINE: string|null, ROW_FORMAT: string|null, TABLE_COLLATION: string|null}>
	 */
	public function getTables(): array
	{
		if ($this->tables === null) {
			$this->tables = $this->fetchTables(null);
		}

		return $this->tables;
	}

	/**
	 * Fetches the full table metadata scoped to requests that declare needsTableMetadata(), and FKs scoped to
	 * requests that declare needsTableMetadata() or includesForeignKeyRelated(), so a database with very many
	 * tables only opens the in-scope ones. When no request needs table metadata or FKs, neither is fetched and
	 * both stay unprimed so getTables()/getForeignKeys() keep their safe whole-database lazy fallbacks.
	 *
	 * @param list<SchemaRequest> $requests
	 */
	public function primeTablesAndForeignKeys(array $requests): void
	{
		[$tableNeeded, $tableWholeDatabase, $tableScope] = $this->tableMetaScope($requests);
		[$fkNeeded, $fkWholeDatabase, $fkScope] = $this->tableMetaScope($requests, true);

		if ($tableNeeded) {
			$this->tables = $tableWholeDatabase ? $this->fetchTables(null) : $this->fetchTables($tableScope);
		}

		if ($fkNeeded) {
			$this->foreignKeys = $fkWholeDatabase ? $this->fetchForeignKeys(null) : $this->fetchForeignKeys($fkScope);
		}

		$this->foreignKeyGraph = null;
	}

	public function isTablesPrimed(): bool
	{
		return $this->tables !== null;
	}

	public function isForeignKeysPrimed(): bool
	{
		return $this->foreignKeys !== null;
	}

	/**
	 * @param list<string>|null $scope null = whole database, [] = nothing
	 * @return list<array<string, mixed>>
	 */
	private function fetchTables(?array $scope): array
	{
		$tablesSelect = 'SELECT TABLE_NAME, ENGINE, ROW_FORMAT, TABLE_COLLATION'
			. ' FROM INFORMATION_SCHEMA.TABLES'
			. " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'";
		$tablesOrder = ' ORDER BY TABLE_NAME';

		if ($scope === null) {
			return $this->dbal->query($tablesSelect . $tablesOrder);
		}

		if ($scope === []) {
			return [];
		}

		return $this->dbal->query(
			$tablesSelect . ' AND TABLE_NAME IN (' . $this->inList($scope) . ')' . $tablesOrder,
		);
	}

	/**
	 * Fetches matching columns ONCE for the UNION of the given requests, expressed as an OR of per-request
	 * predicates (each a charset condition AND an optional table-scope condition), and rebuilds the by-table
	 * index. Always overwrites the cache so a re-prime (e.g. after a migration is applied) re-queries.
	 *
	 * @param list<SchemaRequest> $requests
	 */
	public function primeColumns(array $requests): void
	{
		$this->columns = $this->fetchColumns($requests);
		$this->columnsByTable = null;
	}

	/**
	 * Fetches index statistics ONCE for the UNION of the table scopes of the requests that need statistics,
	 * and rebuilds the by-table index. Always overwrites the cache.
	 *
	 * @param list<SchemaRequest> $requests
	 */
	public function primeStatistics(array $requests): void
	{
		$this->statistics = $this->fetchStatistics($requests);
		$this->statisticsByTable = null;
	}

	public function isColumnsPrimed(): bool
	{
		return $this->columns !== null;
	}

	public function isStatisticsPrimed(): bool
	{
		return $this->statistics !== null;
	}

	/**
	 * @return list<array{TABLE_NAME: string, COLUMN_NAME: string, COLUMN_TYPE: string, DATA_TYPE: string, CHARACTER_SET_NAME: string|null, COLLATION_NAME: string|null, IS_NULLABLE: string, COLUMN_DEFAULT: string|null, EXTRA: string, COLUMN_COMMENT: string, GENERATION_EXPRESSION: string|null, CHARACTER_MAXIMUM_LENGTH: int|null, ORDINAL_POSITION: int}>
	 */
	public function getColumns(): array
	{
		if ($this->columns === null) {
			$this->columns = $this->fetchColumns([new SchemaRequest(ColumnCharsetClass::any())]);
		}

		return $this->columns;
	}

	/**
	 * @return list<array{TABLE_NAME: string, INDEX_NAME: string, NON_UNIQUE: int, SEQ_IN_INDEX: int, COLUMN_NAME: string, SUB_PART: int|null}>
	 */
	public function getStatistics(): array
	{
		if ($this->statistics === null) {
			$this->statistics = $this->dbal->query(
				self::StatisticsSelect . ' WHERE TABLE_SCHEMA = DATABASE()' . self::StatisticsOrder,
			);
		}

		return $this->statistics;
	}

	/**
	 * @param list<SchemaRequest> $requests
	 * @return list<array<string, mixed>>
	 */
	private function fetchColumns(array $requests): array
	{
		if ($requests === []) {
			return [];
		}

		$predicates = '';
		foreach ($requests as $request) {
			if ($predicates !== '') {
				$predicates .= ' OR ';
			}

			$predicates .= $this->columnPredicate($request);
		}

		$sql = self::ColumnsSelect . ' WHERE TABLE_SCHEMA = DATABASE() AND (' . $predicates . ')'
			. self::ColumnsOrder;

		return $this->dbal->query($sql);
	}

	/**
	 * @param list<SchemaRequest> $requests
	 * @return list<array<string, mixed>>
	 */
	private function fetchStatistics(array $requests): array
	{
		$needed = false;
		$wholeDatabase = false;
		$tableNames = [];
		foreach ($requests as $request) {
			if (!$request->needsStatistics()) {
				continue;
			}

			$needed = true;
			$scope = $this->tableScope($request);
			if ($scope === null) {
				$wholeDatabase = true;

				continue;
			}

			foreach ($scope as $name) {
				$tableNames[$name] = true;
			}
		}

		if (!$needed) {
			return [];
		}

		if ($wholeDatabase) {
			return $this->dbal->query(
				self::StatisticsSelect . ' WHERE TABLE_SCHEMA = DATABASE()' . self::StatisticsOrder,
			);
		}

		if ($tableNames === []) {
			return [];
		}

		$sql = self::StatisticsSelect . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('
			. $this->inList(array_keys($tableNames)) . ')' . self::StatisticsOrder;

		return $this->dbal->query($sql);
	}

	/**
	 * @return literal-string
	 */
	private function columnPredicate(SchemaRequest $request): string
	{
		$charsetCondition = $this->charsetCondition($request->getColumnCharsetClass());
		$tableCondition = $this->tableCondition($request);

		if ($charsetCondition === null && $tableCondition === null) {
			return '1 = 1';
		}

		if ($charsetCondition === null) {
			return '(' . $tableCondition . ')';
		}

		if ($tableCondition === null) {
			return '(' . $charsetCondition . ')';
		}

		return '(' . $charsetCondition . ' AND ' . $tableCondition . ')';
	}

	/**
	 * An `any` request carries no charset predicate at all, so columns with CHARACTER_SET_NAME IS NULL
	 * (ints, dates) are included too — the full unfiltered column set, scoped only by the request's tables.
	 *
	 * @return literal-string|null
	 */
	private function charsetCondition(ColumnCharsetClass $class): ?string
	{
		if ($class === ColumnCharsetClass::any()) {
			return null;
		}

		if ($class === ColumnCharsetClass::nonUtf8mb4()) {
			return "CHARACTER_SET_NAME IS NOT NULL AND CHARACTER_SET_NAME <> 'utf8mb4'";
		}

		$names = [];
		foreach ($this->getCharacterSets() as $charset) {
			if ($charset['MAXLEN'] === 1) {
				$names[] = $charset['CHARACTER_SET_NAME'];
			}
		}

		if ($names === []) {
			return '1 = 0';
		}

		return 'CHARACTER_SET_NAME IN (' . $this->inList($names) . ')';
	}

	/**
	 * @return literal-string|null null = whole database (no table excluded)
	 */
	private function tableCondition(SchemaRequest $request): ?string
	{
		$scope = $this->tableScope($request);
		if ($scope === null) {
			return null;
		}

		if ($scope === []) {
			return '1 = 0';
		}

		return 'TABLE_NAME IN (' . $this->inList($scope) . ')';
	}

	/**
	 * The names of the tables a request covers: the non-excluded tables, plus (when the request asks for it)
	 * the other-side tables of any foreign key touching an included one. Returns null when nothing is
	 * excluded (the predicate then omits its table filter and matches the whole database).
	 *
	 * @return list<string>|null
	 */
	private function tableScope(SchemaRequest $request): ?array
	{
		$exclude = $request->getExcludeTables();
		if ($exclude->isEmpty()) {
			return null;
		}

		$included = [];
		$excludedAny = false;
		foreach ($this->getTableNames() as $name) {
			if ($exclude->matches($name)) {
				$excludedAny = true;

				continue;
			}

			$included[$name] = true;
		}

		if (!$excludedAny) {
			return null;
		}

		if ($request->includesForeignKeyRelated()) {
			foreach ($this->getForeignKeyGraph()->getTouching($included) as $constraint) {
				$included[$constraint->table] = true;
				$included[$constraint->referencedTable] = true;
			}
		}

		return array_keys($included);
	}

	/**
	 * @param list<string> $values
	 * @return literal-string
	 */
	private function inList(array $values): string
	{
		$list = '';
		foreach ($values as $value) {
			if ($list !== '') {
				$list .= ', ';
			}

			$list .= $this->dbal->escapeString($value);
		}

		return $list;
	}

	/**
	 * @return list<array{CONSTRAINT_NAME: string, TABLE_NAME: string, COLUMN_NAME: string, ORDINAL_POSITION: int, REFERENCED_TABLE_NAME: string, REFERENCED_COLUMN_NAME: string, UPDATE_RULE: string, DELETE_RULE: string}>
	 */
	public function getForeignKeys(): array
	{
		if ($this->foreignKeys === null) {
			$this->foreignKeys = $this->fetchForeignKeys(null);
		}

		return $this->foreignKeys;
	}

	/**
	 * Replaces the single KEY_COLUMN_USAGE JOIN REFERENTIAL_CONSTRAINTS with two scoped queries merged in
	 * PHP: the per-column FK rows (KCU) and the per-constraint referential actions (RC), joined by
	 * (TABLE_NAME, CONSTRAINT_NAME). The JOIN is an inner one, so a KCU row without a matching RC row is
	 * dropped here too. Both queries carry the same OR-scope (a constraint stays in scope when either of its
	 * tables is in the set), so every kept KCU row has its rule available. The merged shape and row order
	 * (TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION) are identical to the former JOIN.
	 *
	 * @param list<string>|null $scope null = whole database, [] = nothing
	 * @return list<array<string, mixed>>
	 */
	private function fetchForeignKeys(?array $scope): array
	{
		if ($scope === []) {
			return [];
		}

		// Both INFORMATION_SCHEMA tables expose TABLE_NAME (child) and REFERENCED_TABLE_NAME (parent), so the
		// same OR-scope applies to each and the rules query stays aligned with the columns query.
		$scopeCondition = '';
		if ($scope !== null) {
			$in = $this->inList($scope);
			$scopeCondition = ' AND (TABLE_NAME IN (' . $in . ') OR REFERENCED_TABLE_NAME IN (' . $in . '))';
		}

		$columnRows = $this->dbal->query(
			'SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION,'
			. ' REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME'
			. ' FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE'
			. ' WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL'
			. $scopeCondition
			. ' ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION',
		);

		$ruleRows = $this->dbal->query(
			'SELECT CONSTRAINT_NAME, TABLE_NAME, UPDATE_RULE, DELETE_RULE'
			. ' FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS'
			. ' WHERE CONSTRAINT_SCHEMA = DATABASE()'
			. $scopeCondition,
		);

		$rules = [];
		foreach ($ruleRows as $rule) {
			$rules[$rule['TABLE_NAME'] . "\0" . $rule['CONSTRAINT_NAME']] = $rule;
		}

		$merged = [];
		foreach ($columnRows as $row) {
			$rule = $rules[$row['TABLE_NAME'] . "\0" . $row['CONSTRAINT_NAME']] ?? null;
			if ($rule === null) {
				continue;
			}

			$row['UPDATE_RULE'] = $rule['UPDATE_RULE'];
			$row['DELETE_RULE'] = $rule['DELETE_RULE'];
			$merged[] = $row;
		}

		return $merged;
	}

	/**
	 * The union table set for scoped table-metadata or FK fetching. When $includeFkRelated is false, only
	 * requests with needsTableMetadata() qualify; when true, requests with includesForeignKeyRelated() also
	 * qualify (FKs are needed for boundary handling regardless of table-metadata need). An empty exclude (or
	 * one that matched no real table) means whole database.
	 *
	 * @param list<SchemaRequest> $requests
	 * @return array{bool, bool, list<string>} [needed, wholeDatabase, scope]
	 */
	private function tableMetaScope(array $requests, bool $includeFkRelated = false): array
	{
		$needed = false;
		$wholeDatabase = false;
		$names = [];
		foreach ($requests as $request) {
			if (!$request->needsTableMetadata() && !($includeFkRelated && $request->includesForeignKeyRelated())) {
				continue;
			}

			$needed = true;
			$exclude = $request->getExcludeTables();
			if ($exclude->isEmpty()) {
				$wholeDatabase = true;

				continue;
			}

			$included = [];
			$excludedAny = false;
			foreach ($this->getTableNames() as $name) {
				if ($exclude->matches($name)) {
					$excludedAny = true;

					continue;
				}

				$included[$name] = true;
			}

			if (!$excludedAny) {
				$wholeDatabase = true;

				continue;
			}

			foreach ($included as $name => $isIncluded) {
				$names[$name] = true;
			}
		}

		return [$needed, $wholeDatabase, array_keys($names)];
	}

	/**
	 * @return array<string, list<array{TABLE_NAME: string, COLUMN_NAME: string, COLUMN_TYPE: string, DATA_TYPE: string, CHARACTER_SET_NAME: string|null, COLLATION_NAME: string|null, IS_NULLABLE: string, COLUMN_DEFAULT: string|null, EXTRA: string, COLUMN_COMMENT: string, GENERATION_EXPRESSION: string|null, CHARACTER_MAXIMUM_LENGTH: int|null, ORDINAL_POSITION: int}>>
	 */
	public function getColumnsByTable(): array
	{
		if ($this->columnsByTable === null) {
			$byTable = [];
			foreach ($this->getColumns() as $column) {
				$byTable[$column['TABLE_NAME']][] = $column;
			}

			$this->columnsByTable = $byTable;
		}

		/**
		 * @var array<string, list<array{TABLE_NAME: string, COLUMN_NAME: string, COLUMN_TYPE: string, DATA_TYPE: string, CHARACTER_SET_NAME: string|null, COLLATION_NAME: string|null, IS_NULLABLE: string, COLUMN_DEFAULT: string|null, EXTRA: string, COLUMN_COMMENT: string, GENERATION_EXPRESSION: string|null, CHARACTER_MAXIMUM_LENGTH: int|null, ORDINAL_POSITION: int}>> $byTable
		 */
		$byTable = $this->columnsByTable;

		return $byTable;
	}

	/**
	 * @return array<string, list<array{TABLE_NAME: string, INDEX_NAME: string, NON_UNIQUE: int, SEQ_IN_INDEX: int, COLUMN_NAME: string, SUB_PART: int|null}>>
	 */
	public function getStatisticsByTable(): array
	{
		if ($this->statisticsByTable === null) {
			$byTable = [];
			foreach ($this->getStatistics() as $stat) {
				$byTable[$stat['TABLE_NAME']][] = $stat;
			}

			$this->statisticsByTable = $byTable;
		}

		/**
		 * @var array<string, list<array{TABLE_NAME: string, INDEX_NAME: string, NON_UNIQUE: int, SEQ_IN_INDEX: int, COLUMN_NAME: string, SUB_PART: int|null}>> $byTable
		 */
		$byTable = $this->statisticsByTable;

		return $byTable;
	}

	public function getForeignKeyGraph(): ForeignKeyGraph
	{
		if ($this->foreignKeyGraph === null) {
			$this->foreignKeyGraph = new ForeignKeyGraph($this->getForeignKeys());
		}

		return $this->foreignKeyGraph;
	}

	/**
	 * @return list<array{CHARACTER_SET_NAME: string, MAXLEN: int}>
	 */
	public function getCharacterSets(): array
	{
		if ($this->characterSets === null) {
			$this->characterSets = $this->dbal->query(
				<<<'SQL'
SELECT CHARACTER_SET_NAME, MAXLEN
FROM INFORMATION_SCHEMA.CHARACTER_SETS
SQL,
			);
		}

		return $this->characterSets;
	}

	/**
	 * @return list<array{COLLATION_NAME: string, CHARACTER_SET_NAME: string}>
	 */
	public function getCollations(): array
	{
		if ($this->collations === null) {
			$this->collations = $this->dbal->query(
				<<<'SQL'
SELECT COLLATION_NAME, CHARACTER_SET_NAME
FROM INFORMATION_SCHEMA.COLLATIONS
SQL,
			);
		}

		return $this->collations;
	}

	/**
	 * @return array{name: string, charset: string, collation: string}
	 */
	public function getDatabaseDefault(): array
	{
		if ($this->databaseDefault === null) {
			$rows = $this->dbal->query(
				<<<'SQL'
SELECT SCHEMA_NAME AS name, DEFAULT_CHARACTER_SET_NAME AS charset, DEFAULT_COLLATION_NAME AS collation
FROM INFORMATION_SCHEMA.SCHEMATA
WHERE SCHEMA_NAME = DATABASE()
SQL,
			);

			$this->databaseDefault = [
				'name' => (string) $rows[0]['name'],
				'charset' => (string) $rows[0]['charset'],
				'collation' => (string) $rows[0]['collation'],
			];
		}

		return $this->databaseDefault;
	}

	/**
	 * @return list<array{TABLE_NAME: string, VIEW_DEFINITION: string}>
	 */
	public function getViews(): array
	{
		if ($this->views === null) {
			$this->views = $this->dbal->query(
				<<<'SQL'
SELECT TABLE_NAME, VIEW_DEFINITION
FROM INFORMATION_SCHEMA.VIEWS
WHERE TABLE_SCHEMA = DATABASE()
ORDER BY TABLE_NAME
SQL,
			);
		}

		/** @var list<array{TABLE_NAME: string, VIEW_DEFINITION: string}> $views */
		$views = $this->views;

		return $views;
	}

	/**
	 * ROUTINE_DEFINITION is reported NULL when the connection lacks the privilege to read the routine body;
	 * such rows carry no inspectable SQL and are dropped so callers always receive a usable definition.
	 *
	 * @return list<array{ROUTINE_NAME: string, ROUTINE_TYPE: string, ROUTINE_DEFINITION: string}>
	 */
	public function getRoutines(): array
	{
		if ($this->routines === null) {
			$rows = $this->dbal->query(
				<<<'SQL'
SELECT ROUTINE_NAME, ROUTINE_TYPE, ROUTINE_DEFINITION
FROM INFORMATION_SCHEMA.ROUTINES
WHERE ROUTINE_SCHEMA = DATABASE()
ORDER BY ROUTINE_NAME
SQL,
			);

			$routines = [];
			foreach ($rows as $row) {
				if ($row['ROUTINE_DEFINITION'] === null) {
					continue;
				}

				$routines[] = $row;
			}

			$this->routines = $routines;
		}

		/** @var list<array{ROUTINE_NAME: string, ROUTINE_TYPE: string, ROUTINE_DEFINITION: string}> $routines */
		$routines = $this->routines;

		return $routines;
	}

	/**
	 * @return list<array{TRIGGER_NAME: string, ACTION_STATEMENT: string}>
	 */
	public function getTriggers(): array
	{
		if ($this->triggers === null) {
			$this->triggers = $this->dbal->query(
				<<<'SQL'
SELECT TRIGGER_NAME, ACTION_STATEMENT
FROM INFORMATION_SCHEMA.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
ORDER BY TRIGGER_NAME
SQL,
			);
		}

		/** @var list<array{TRIGGER_NAME: string, ACTION_STATEMENT: string}> $triggers */
		$triggers = $this->triggers;

		return $triggers;
	}

	/**
	 * @return list<array{EVENT_NAME: string, EVENT_DEFINITION: string}>
	 */
	public function getEvents(): array
	{
		if ($this->events === null) {
			$this->events = $this->dbal->query(
				<<<'SQL'
SELECT EVENT_NAME, EVENT_DEFINITION
FROM INFORMATION_SCHEMA.EVENTS
WHERE EVENT_SCHEMA = DATABASE()
ORDER BY EVENT_NAME
SQL,
			);
		}

		/** @var list<array{EVENT_NAME: string, EVENT_DEFINITION: string}> $events */
		$events = $this->events;

		return $events;
	}

}

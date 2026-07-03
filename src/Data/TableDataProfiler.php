<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Data;

use Orisai\DbAudit\Schema\SchemaProvider;
use function array_key_exists;
use function count;
use function in_array;
use function strtolower;

final class TableDataProfiler
{

	private const StringTypes = ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext'];

	private const IntTypes = ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'];

	private const DateTypes = ['date', 'datetime', 'timestamp'];

	private SchemaProvider $schema;

	/** @var array<string, array<string, mixed>|null> */
	private array $profiles = [];

	public function __construct(SchemaProvider $schema)
	{
		$this->schema = $schema;
	}

	public function reset(): void
	{
		$this->profiles = [];
	}

	/**
	 * @return array{rowCount: int, nonNull: array<string, int>, nonEmpty: array<string, int>, nonBool: array<string, int>, invalidDate: array<string, int>}|null
	 */
	public function getProfile(string $table): ?array
	{
		if (!array_key_exists($table, $this->profiles)) {
			$this->profiles[$table] = $this->computeProfile($table);
		}

		/**
		 * @var array{rowCount: int, nonNull: array<string, int>, nonEmpty: array<string, int>, nonBool: array<string, int>, invalidDate: array<string, int>}|null $profile
		 */
		$profile = $this->profiles[$table];

		return $profile;
	}

	/**
	 * @return array{rowCount: int, nonNull: array<string, int>, nonEmpty: array<string, int>, nonBool: array<string, int>, invalidDate: array<string, int>}|null
	 */
	private function computeProfile(string $table): ?array
	{
		// Only primed base tables are profiled — a view here would execute the view's query, and the
		// provider's global exclude must hold even when another request's union primed a wider column set.
		if (!$this->isKnownBaseTable($table) || $this->schema->getExclude()->matches($table)) {
			return null;
		}

		$columns = $this->schema->getColumnsByTable()[$table] ?? [];

		$dbal = $this->schema->getDbal();
		$expressions = ['COUNT(*) AS m0'];
		// column name => alias, in column order; null alias means NOT NULL — derived from rowCount below
		$nonNullPlan = [];
		// alias => [metric key, column name]
		$aliases = [];

		foreach ($columns as $column) {
			$name = $column['COLUMN_NAME'];
			$id = $dbal->escapeIdentifier($name);
			$type = strtolower($column['DATA_TYPE']);

			if ($column['IS_NULLABLE'] === 'YES') {
				$alias = 'm' . count($expressions);
				$expressions[] = 'COUNT(' . $id . ') AS ' . $alias;
				$nonNullPlan[$name] = $alias;
			} else {
				$nonNullPlan[$name] = null;
			}

			if (in_array($type, self::StringTypes, true)) {
				$alias = 'm' . count($expressions);
				$expressions[] = 'SUM(' . $id . ' IS NOT NULL AND ' . $id . " <> '') AS " . $alias;
				$aliases[$alias] = ['nonEmpty', $name];
			}

			if (in_array($type, self::IntTypes, true)) {
				$alias = 'm' . count($expressions);
				$expressions[] = 'SUM(' . $id . ' NOT IN (0, 1)) AS ' . $alias;
				$aliases[$alias] = ['nonBool', $name];
			}

			if (in_array($type, self::DateTypes, true)) {
				$alias = 'm' . count($expressions);
				$expressions[] = 'SUM(YEAR(' . $id . ') = 0 OR MONTH(' . $id . ') = 0 OR DAY(' . $id . ') = 0)'
					. ' AS ' . $alias;
				$aliases[$alias] = ['invalidDate', $name];
			}
		}

		$sql = 'SELECT ';
		foreach ($expressions as $i => $expression) {
			$sql .= ($i === 0 ? '' : ', ') . $expression;
		}

		$sql .= ' FROM ' . $dbal->escapeIdentifier($table);

		// phpcs:ignore SlevomatCodingStandard.PHP.RequireExplicitAssertion.RequiredExplicitAssertion
		/** @var literal-string $literalSql */
		$literalSql = $sql;

		$row = $dbal->query($literalSql)[0];

		$profile = [
			'rowCount' => (int) $row['m0'],
			'nonNull' => [],
			'nonEmpty' => [],
			'nonBool' => [],
			'invalidDate' => [],
		];

		foreach ($nonNullPlan as $name => $alias) {
			// SUM()/COUNT() over zero rows is NULL — an empty table means a zero count for every metric
			$profile['nonNull'][$name] = $alias === null ? $profile['rowCount'] : (int) ($row[$alias] ?? 0);
		}

		foreach ($aliases as $alias => [$metric, $name]) {
			$profile[$metric][$name] = (int) ($row[$alias] ?? 0);
		}

		return $profile;
	}

	private function isKnownBaseTable(string $table): bool
	{
		foreach ($this->schema->getTables() as $row) {
			if ($row['TABLE_NAME'] === $table) {
				return true;
			}
		}

		return false;
	}

}

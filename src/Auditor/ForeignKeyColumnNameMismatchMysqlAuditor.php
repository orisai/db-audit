<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Collation\TableNameFilter;
use Orisai\DbAudit\Report\AnalysisResult;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;
use Orisai\DbAudit\Schema\ColumnCharsetClass;
use Orisai\DbAudit\Schema\SchemaRequest;
use function preg_match;
use function strcmp;
use function stripos;
use function usort;

final class ForeignKeyColumnNameMismatchMysqlAuditor extends ForeignKeyColumnNameMismatchAuditor
{

	/**
	 * Every base table is scanned column by column and cross-referenced with its foreign keys and its PRIMARY
	 * index, so columns of all tables, the foreign-key graph, index statistics (PRIMARY detection) and the
	 * base-table set (table metadata) are needed; no exclude-driven scope expansion.
	 */
	public function getSchemaRequest(): SchemaRequest
	{
		return new SchemaRequest(
			ColumnCharsetClass::any(),
			new TableNameFilter(),
			false,
			true,
			true,
		);
	}

	public function analyse(): AnalysisResult
	{
		$this->refreshOwnedSchema();

		$db = $this->schema->getDatabaseDefault()['name'];
		$pattern = $this->config->getPattern();

		$baseTables = [];
		foreach ($this->schema->getTables() as $table) {
			$baseTables[$table['TABLE_NAME']] = true;
		}

		$fkColumns = [];
		foreach ($this->schema->getForeignKeyGraph()->getConstraints() as $constraint) {
			foreach ($constraint->columns as $column) {
				$fkColumns[$constraint->table . "\0" . $column] = true;
			}
		}

		$statisticsByTable = $this->schema->getStatisticsByTable();

		// Each entry carries its (table, column) sort key so the emitted order is (table, column).
		$entries = [];
		foreach ($this->schema->getColumns() as $column) {
			$table = $column['TABLE_NAME'];
			if (!isset($baseTables[$table])) {
				continue;
			}

			$name = $column['COLUMN_NAME'];
			$matchesPattern = preg_match($pattern, $name) === 1;
			$isForeignKey = isset($fkColumns[$table . "\0" . $name]);

			if ($matchesPattern && !$isForeignKey) {
				if ($this->isPrimaryKeyColumn($statisticsByTable, $table, $name)) {
					continue;
				}

				if (stripos($column['EXTRA'], 'auto_increment') !== false) {
					continue;
				}

				$source = new ColumnViolationSource($db, null, $table, $name);
				$entries[] = [
					'table' => $table,
					'column' => $name,
					'violation' => new Violation(
						'foreign_key.missing_constraint',
						'Column ' . $source->toString()
						. ' matches the foreign-key naming pattern but has no foreign key.',
						$source,
					),
				];
			} elseif (!$matchesPattern && $isForeignKey) {
				$source = new ColumnViolationSource($db, null, $table, $name);
				$entries[] = [
					'table' => $table,
					'column' => $name,
					'violation' => new Violation(
						'foreign_key.unexpected_name',
						'Column ' . $source->toString()
						. ' has a foreign key but does not match the foreign-key naming pattern.',
						$source,
					),
				];
			}
		}

		usort(
			$entries,
			static function (array $a, array $b): int {
				if ($a['table'] !== $b['table']) {
					return strcmp($a['table'], $b['table']);
				}

				return strcmp($a['column'], $b['column']);
			},
		);

		$violations = [];
		foreach ($entries as $entry) {
			$violations[] = $entry['violation'];
		}

		return new AnalysisResult($violations);
	}

	/**
	 * @param array<string, list<array{TABLE_NAME: string, INDEX_NAME: string, NON_UNIQUE: int, SEQ_IN_INDEX: int, COLUMN_NAME: string, SUB_PART: int|null}>> $statisticsByTable
	 */
	private function isPrimaryKeyColumn(array $statisticsByTable, string $table, string $column): bool
	{
		foreach ($statisticsByTable[$table] ?? [] as $stat) {
			if ($stat['INDEX_NAME'] === 'PRIMARY' && $stat['COLUMN_NAME'] === $column) {
				return true;
			}
		}

		return false;
	}

}

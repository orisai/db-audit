<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Report\AnalysisResult;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;
use Orisai\DbAudit\Schema\ColumnCharsetClass;
use Orisai\DbAudit\Schema\SchemaRequest;
use Orisai\DbAudit\Schema\TableExclude;
use function strcmp;
use function usort;

final class ForeignKeyReferencedColumnExistenceMysqlAuditor extends ForeignKeyReferencedColumnExistenceAuditor
{

	/**
	 * Every foreign key in the whole database is checked against the existing-table set, so the foreign-key
	 * graph and table names (table metadata) are needed; no statistics and no exclude-driven scope expansion.
	 */
	public function getSchemaRequest(): SchemaRequest
	{
		return new SchemaRequest(
			ColumnCharsetClass::any(),
			new TableExclude(),
			false,
			false,
			true,
		);
	}

	public function analyse(): AnalysisResult
	{
		$this->refreshOwnedSchema();

		$existingTables = [];
		foreach ($this->schema->getTableNames() as $name) {
			$existingTables[$name] = true;
		}

		$db = $this->schema->getDatabaseDefault()['name'];

		// Each entry carries its (table, column) sort key so the emitted order reproduces the former
		// ORDER BY TABLE_NAME, COLUMN_NAME.
		$entries = [];
		foreach ($this->schema->getForeignKeyGraph()->getConstraints() as $fk) {
			if (isset($existingTables[$fk->referencedTable])) {
				continue;
			}

			foreach ($fk->columns as $i => $column) {
				$referencedColumn = $fk->referencedColumns[$i];

				$source = new ColumnViolationSource($db, null, $fk->table, $column);
				$referencedSource = new ColumnViolationSource($db, null, $fk->referencedTable, $referencedColumn);

				$entries[] = [
					'table' => $fk->table,
					'column' => $column,
					'violation' => new Violation(
						'foreign_key.referenced_table_missing',
						'Foreign key of column '
						. $source->toString()
						. ' references column '
						. $referencedSource->toString()
						. ' but the referenced table does not exist.',
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

}

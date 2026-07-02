<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Report\AnalysisResult;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;
use Orisai\DbAudit\Schema\ColumnCharsetClass;
use Orisai\DbAudit\Schema\ForeignKeyConstraint;
use Orisai\DbAudit\Schema\SchemaRequest;
use Orisai\DbAudit\Schema\TableExclude;
use function implode;
use function strcmp;
use function usort;

final class ForeignKeyViolationMysqlAuditor extends ForeignKeyViolationAuditor
{

	/**
	 * Every foreign key in the whole database is scanned for orphan rows, so the foreign-key graph and table
	 * names (table metadata) are needed; no statistics and no exclude-driven scope expansion.
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
		$existingTables = [];
		foreach ($this->schema->getTableNames() as $name) {
			$existingTables[$name] = true;
		}

		$db = $this->schema->getDatabaseDefault()['name'];

		// Each entry carries its (table, first column) sort key so the emitted order is stable.
		$entries = [];
		foreach ($this->schema->getForeignKeyGraph()->getConstraints() as $fk) {
			// An FK whose parent table does not exist is the referenced-table auditor's concern, not an orphan.
			if (!isset($existingTables[$fk->referencedTable])) {
				continue;
			}

			if (!$this->hasOrphan($fk)) {
				continue;
			}

			$source = new ColumnViolationSource($db, null, $fk->table, $fk->columns[0]);
			$referencedSource = new ColumnViolationSource($db, null, $fk->referencedTable, $fk->referencedColumns[0]);

			$entries[] = [
				'table' => $fk->table,
				'column' => $fk->columns[0],
				'violation' => new Violation(
					'foreign_key.violation',
					'Foreign key of column '
					. $source->toString()
					. ' references column '
					. $referencedSource->toString()
					. ' but some of the referenced records do not exist.',
					$source,
					false,
					'Delete or fix the orphan rows before relying on the constraint.',
				),
			];
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
	 * A child row violates the FK iff all its referencing columns are non-NULL (MySQL MATCH SIMPLE: a NULL in
	 * any referencing column means the row is not checked) and no parent row matches the full referenced tuple.
	 * The composite tuple is checked at once, so a multi-column FK is not (incorrectly) judged column by column.
	 */
	private function hasOrphan(ForeignKeyConstraint $fk): bool
	{
		$joinConditions = [];
		$notNullConditions = [];
		foreach ($fk->columns as $i => $column) {
			$childColumn = 't.' . $this->dbal->escapeIdentifier($column);
			$parentColumn = 'r.' . $this->dbal->escapeIdentifier($fk->referencedColumns[$i]);
			$joinConditions[] = $childColumn . ' = ' . $parentColumn;
			$notNullConditions[] = $childColumn . ' IS NOT NULL';
		}

		// The referenced columns are key columns, so any of them being NULL after the LEFT JOIN means no
		// matching parent tuple was found.
		$missingParent = 'r.' . $this->dbal->escapeIdentifier($fk->referencedColumns[0]) . ' IS NULL';

		$sql = 'SELECT 1 FROM ' . $this->dbal->escapeIdentifier($fk->table) . ' t'
			. ' LEFT JOIN ' . $this->dbal->escapeIdentifier($fk->referencedTable) . ' r'
			. ' ON (' . implode(' AND ', $joinConditions) . ')'
			. ' WHERE ' . implode(' AND ', $notNullConditions)
			. ' AND ' . $missingParent
			. ' LIMIT 1';

		return $this->dbal->query($sql) !== [];
	}

}

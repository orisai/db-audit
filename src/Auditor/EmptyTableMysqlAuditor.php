<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Report\AnalysisResult;
use Orisai\DbAudit\Report\TableViolationSource;
use Orisai\DbAudit\Report\Violation;

final class EmptyTableMysqlAuditor extends EmptyTableAuditor
{

	public function analyse(): AnalysisResult
	{
		$db = $this->schema->getDatabaseDefault()['name'];

		$violations = [];
		foreach ($this->schema->getTables() as $tableRow) {
			$table = $tableRow['TABLE_NAME'];
			if ($this->schema->getExclude()->matches($table)) {
				continue;
			}

			if (!$this->isTableEmpty($db, $table)) {
				continue;
			}

			$source = new TableViolationSource($db, null, $table);

			$violations[] = new Violation(
				'empty_table',
				'Table '
				. $source->toString()
				. ' is empty.',
				$source,
			);
		}

		return new AnalysisResult($violations);
	}

	private function isTableEmpty(string $schema, string $table): bool
	{
		$tableId = $this->dbal->escapeIdentifier($schema) . '.' . $this->dbal->escapeIdentifier($table);

		// INFORMATION_SCHEMA.TABLES.TABLE_ROWS is only an InnoDB estimate (and is cached for up to a day on
		// MySQL), so a non-empty table can report 0; probe each table for an actual row instead.
		return $this->dbal->query('SELECT 1 FROM ' . $tableId . ' LIMIT 1') === [];
	}

}

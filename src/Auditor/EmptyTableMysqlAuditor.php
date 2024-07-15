<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Report\AnalysisResult;
use Orisai\DbAudit\Report\TableViolationSource;
use Orisai\DbAudit\Report\Violation;

final class EmptyTableMysqlAuditor extends EmptyTableAuditor
{

	public function analyse(): AnalysisResult
	{
		$violations = [];
		foreach ($this->getRecords() as $record) {
			if (!$this->isTableEmpty($record['TABLE_SCHEMA'], $record['TABLE_NAME'])) {
				continue;
			}

			$source = new TableViolationSource(
				$record['TABLE_SCHEMA'],
				null,
				$record['TABLE_NAME'],
			);

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

	/**
	 * @return list<array{
	 *     TABLE_SCHEMA: string,
	 *     TABLE_NAME: string,
	 * }>
	 */
	private function getRecords(): array
	{
		return $this->dbal->query(
		/** @lang MySQL */
			<<<'SQL'
SELECT
	TABLE_SCHEMA,
	TABLE_NAME
FROM
	INFORMATION_SCHEMA.TABLES
WHERE
	TABLE_SCHEMA = DATABASE()
	AND TABLE_TYPE = 'BASE TABLE'
ORDER BY TABLE_SCHEMA, TABLE_NAME;
SQL,
		);
	}

}

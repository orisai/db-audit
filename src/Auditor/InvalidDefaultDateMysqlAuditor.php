<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;

final class InvalidDefaultDateMysqlAuditor extends InvalidDefaultDateAuditor
{

	public function analyse(): array
	{
		$records = $this->getRecords();

		$violations = [];
		foreach ($records as $record) {
			$source = new ColumnViolationSource(
				$record['TABLE_SCHEMA'],
				null,
				$record['TABLE_NAME'],
				$record['COLUMN_NAME'],
			);
			$source->setColumnType($record['COLUMN_TYPE']);

			$message = 'Invalid default value '
				. $record['COLUMN_DEFAULT']
				. ' in '
				. $source->toString();

			$violations[] = new Violation(self::getKey(), $message, $source);
		}

		return $violations;
	}

	/**
	 * @return list<array{
	 *     TABLE_SCHEMA: string,
	 *     TABLE_NAME: string,
	 *     COLUMN_NAME: string,
	 *     COLUMN_DEFAULT: string,
	 *     COLUMN_TYPE: string,
	 * }>
	 */
	private function getRecords(): array
	{
		return $this->dbal->query(
		/** @lang MySQL */
			<<<'SQL'
SELECT c.TABLE_SCHEMA, c.TABLE_NAME, c.COLUMN_NAME, c.COLUMN_DEFAULT, c.COLUMN_TYPE
FROM INFORMATION_SCHEMA.COLUMNS c
	INNER JOIN INFORMATION_SCHEMA.TABLES t ON c.TABLE_NAME = t.TABLE_NAME AND c.TABLE_SCHEMA = t.TABLE_SCHEMA
WHERE c.TABLE_SCHEMA = DATABASE()
	AND c.DATA_TYPE IN ('date', 'datetime', 'timestamp')
	AND (
		c.COLUMN_DEFAULT LIKE '%00-__-__%' -- year
		OR c.COLUMN_DEFAULT LIKE '%__-00-__%' -- month
		OR c.COLUMN_DEFAULT LIKE '%__-__-00%' -- day
	)
ORDER BY c.TABLE_SCHEMA, c.TABLE_NAME, c.COLUMN_NAME;
SQL,
		);
	}

}

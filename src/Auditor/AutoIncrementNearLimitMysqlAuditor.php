<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;

final class AutoIncrementNearLimitMysqlAuditor extends AutoIncrementNearLimitAuditor
{

	public function analyse(): array
	{
		//TODO - kontrolovat poslední datum analýzy a reportovat, že může být outdated
		//		- to by bylo dobré i jako samostatná kontrola, záleží na ní execution plan
		//		- https://www.percona.com/blog/correcting-mysql-inaccurate-table-statistics-for-better-execution-plan/
		//TODO - započítávat steps
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

			$message = 'Autoincrement is above threshold of '
				. $this->percentileThreshold
				. '% in '
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
	 *     COLUMN_TYPE: string,
	 *     AUTO_INCREMENT: int,
	 * }>
	 */
	public function getRecords(): array
	{
		$threshold = $this->dbal->escapeInt($this->percentileThreshold);

		return $this->dbal->query(
		/** @lang MySQL */
			<<<SQL
SELECT
	c.TABLE_SCHEMA,
	c.TABLE_NAME,
	c.COLUMN_NAME,
	c.COLUMN_TYPE,
	t.AUTO_INCREMENT,
	(CASE
		WHEN c.DATA_TYPE = 'tinyint' AND c.COLUMN_TYPE LIKE '%unsigned%' THEN 255
		WHEN c.DATA_TYPE = 'tinyint' THEN 127
		WHEN c.DATA_TYPE = 'smallint' AND c.COLUMN_TYPE LIKE '%unsigned%' THEN 65535
		WHEN c.DATA_TYPE = 'smallint' THEN 32767
		WHEN c.DATA_TYPE = 'mediumint' AND c.COLUMN_TYPE LIKE '%unsigned%' THEN 16777215
		WHEN c.DATA_TYPE = 'mediumint' THEN 8388607
		WHEN c.DATA_TYPE = 'int' AND c.COLUMN_TYPE LIKE '%unsigned%' THEN 4294967295
		WHEN c.DATA_TYPE = 'int' THEN 2147483647
		WHEN c.DATA_TYPE = 'bigint' AND c.COLUMN_TYPE LIKE '%unsigned%' THEN 18446744073709551615
		WHEN c.DATA_TYPE = 'bigint' THEN 9223372036854775807
		ELSE 0
	END) AS MAX_VALUE,
	(t.AUTO_INCREMENT / (CASE
		WHEN c.DATA_TYPE = 'tinyint' AND c.COLUMN_TYPE LIKE '%unsigned%' THEN 255
		WHEN c.DATA_TYPE = 'tinyint' THEN 127
		WHEN c.DATA_TYPE = 'smallint' AND c.COLUMN_TYPE LIKE '%unsigned%' THEN 65535
		WHEN c.DATA_TYPE = 'smallint' THEN 32767
		WHEN c.DATA_TYPE = 'mediumint' AND c.COLUMN_TYPE LIKE '%unsigned%' THEN 16777215
		WHEN c.DATA_TYPE = 'mediumint' THEN 8388607
		WHEN c.DATA_TYPE = 'int' AND c.COLUMN_TYPE LIKE '%unsigned%' THEN 4294967295
		WHEN c.DATA_TYPE = 'int' THEN 2147483647
		WHEN c.DATA_TYPE = 'bigint' AND c.COLUMN_TYPE LIKE '%unsigned%' THEN 18446744073709551615
		WHEN c.DATA_TYPE = 'bigint' THEN 9223372036854775807
		ELSE 1
	END) * 100) AS PERCENTAGE_USED
FROM INFORMATION_SCHEMA.TABLES t
JOIN INFORMATION_SCHEMA.COLUMNS c ON t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_SCHEMA = c.TABLE_SCHEMA
WHERE c.TABLE_SCHEMA = DATABASE()
	AND c.EXTRA LIKE '%auto_increment%'
	AND t.AUTO_INCREMENT IS NOT NULL
HAVING PERCENTAGE_USED >= $threshold
ORDER BY c.TABLE_SCHEMA, c.TABLE_NAME, c.COLUMN_NAME;
SQL,
		);
	}

}

<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Report\AnalysisResult;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;

final class InvalidDateMysqlAuditor extends InvalidDateAuditor
{

	public function analyse(): AnalysisResult
	{
		$this->createProcedure();

		try {
			$records = $this->getRecords();
		} finally {
			$this->cleanup();
		}

		$violations = [];
		foreach ($records as $record) {
			$source = new ColumnViolationSource(
				$record['TABLE_SCHEMA'],
				null,
				$record['TABLE_NAME'],
				$record['COLUMN_NAME'],
			);

			$violations[] = new Violation(
				'invalid_date',
				'Column '
				. $source->toString()
				. ' contains invalid dates.',
				$source,
			);
		}

		return new AnalysisResult($violations);
	}

	private function createProcedure(): void
	{
		// A run killed before cleanup() leaves the procedure behind; drop it first so CREATE never collides.
		$this->dbal->exec(
		/** @lang MySQL */
			'DROP PROCEDURE IF EXISTS OrisaiDbAudit_FindInvalidDates;',
		);

		$this->dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE PROCEDURE OrisaiDbAudit_FindInvalidDates()
BEGIN
	DECLARE fetched_table_schema VARCHAR(64) CHARACTER SET utf8mb4;
	DECLARE fetched_table_name VARCHAR(64) CHARACTER SET utf8mb4;
	DECLARE fetched_column_name VARCHAR(64) CHARACTER SET utf8mb4;
	DECLARE fetched_data_type VARCHAR(64) CHARACTER SET utf8mb4;

	DECLARE done INT DEFAULT 0;

	DECLARE cur CURSOR FOR
		SELECT c.TABLE_SCHEMA, c.TABLE_NAME, c.COLUMN_NAME, c.DATA_TYPE
		FROM INFORMATION_SCHEMA.COLUMNS c
		JOIN INFORMATION_SCHEMA.TABLES t
			ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
		WHERE c.TABLE_SCHEMA = DATABASE()
			AND t.TABLE_TYPE = 'BASE TABLE'
			AND c.DATA_TYPE IN ('date', 'datetime', 'timestamp')
		ORDER BY c.TABLE_SCHEMA, c.TABLE_NAME, c.COLUMN_NAME;

	DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

	DROP TEMPORARY TABLE IF EXISTS OrisaiDbAudit_invalid_dates;
	CREATE TEMPORARY TABLE OrisaiDbAudit_invalid_dates (
		TABLE_SCHEMA VARCHAR(64) CHARACTER SET utf8mb4,
		TABLE_NAME VARCHAR(64) CHARACTER SET utf8mb4,
		COLUMN_NAME VARCHAR(64) CHARACTER SET utf8mb4,
		DATA_TYPE VARCHAR(64) CHARACTER SET utf8mb4
	);

	OPEN cur;

	read_loop: LOOP
		FETCH cur INTO fetched_table_schema, fetched_table_name, fetched_column_name, fetched_data_type;
		IF done THEN
			LEAVE read_loop;
		END IF;

		-- Identifiers are backtick-quoted (with embedded backticks doubled) and the echoed literal values are
		-- single-quoted (with embedded single quotes doubled) so reserved-word/hyphenated/quote-containing names
		-- do not break the dynamic SQL.
		SET @query = CONCAT(
			'INSERT INTO OrisaiDbAudit_invalid_dates (TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, DATA_TYPE) ',
			'SELECT ''', REPLACE(fetched_table_schema, '''', ''''''), ''', ',
			'''', REPLACE(fetched_table_name, '''', ''''''), ''', ',
			'''', REPLACE(fetched_column_name, '''', ''''''), ''', ',
			'''', REPLACE(fetched_data_type, '''', ''''''), ''' ',
			'FROM `', REPLACE(fetched_table_schema, '`', '``'), '`.`', REPLACE(fetched_table_name, '`', '``'), '` ',
			'WHERE YEAR(`', REPLACE(fetched_column_name, '`', '``'), '`) = 0 ',
			'OR MONTH(`', REPLACE(fetched_column_name, '`', '``'), '`) = 0 ',
			'OR DAY(`', REPLACE(fetched_column_name, '`', '``'), '`) = 0 LIMIT 1'
		);

		PREPARE stmt FROM @query;
		EXECUTE stmt;
		DEALLOCATE PREPARE stmt;
	END LOOP;

	CLOSE cur;
END;
SQL,
		);
	}

	private function cleanup(): void
	{
		$this->dbal->exec(
		/** @lang MySQL */
			'DROP PROCEDURE IF EXISTS OrisaiDbAudit_FindInvalidDates;',
		);

		$this->dbal->exec(
		/** @lang MySQL */
			'DROP TEMPORARY TABLE IF EXISTS OrisaiDbAudit_invalid_dates;',
		);
	}

	/**
	 * @return list<array{
	 *     TABLE_SCHEMA: string,
	 *     TABLE_NAME: string,
	 *     COLUMN_NAME: string,
	 *     DATA_TYPE: string,
	 * }>
	 */
	private function getRecords(): array
	{
		$this->dbal->exec(
		/** @lang MySQL */
			'CALL OrisaiDbAudit_FindInvalidDates();',
		);

		return $this->dbal->query(
		/** @lang MySQL */
			'SELECT * FROM OrisaiDbAudit_invalid_dates ORDER BY TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME',
		);
	}

}

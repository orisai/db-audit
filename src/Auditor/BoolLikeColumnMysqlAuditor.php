<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Report\AnalysisResult;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;
use function stripos;

final class BoolLikeColumnMysqlAuditor extends BoolLikeColumnAuditor
{

	public function analyse(): AnalysisResult
	{
		$this->createProcedure();

		try {
			$records = $this->getRecords();
			$checks = $this->getChecks();
		} finally {
			$this->cleanup();
		}

		$groupedChecks = [];
		foreach ($checks as $check) {
			$groupedChecks[$check['TABLE_SCHEMA']][$check['TABLE_NAME']][$check['COLUMN_NAME']][] = $check['CHECK_CLAUSE'];
		}

		$violations = [];
		foreach ($records as $record) {
			$source = new ColumnViolationSource(
				$record['TABLE_SCHEMA'],
				null,
				$record['TABLE_NAME'],
				$record['COLUMN_NAME'],
			);
			$source->setColumnType($record['COLUMN_TYPE']);

			if ($record['DATA_TYPE'] !== 'tinyint') {
				$violations[] = new Violation(
					'bool_like_column',
					'Column '
					. $source->toString()
					. ' contains only 0 and 1 but is not defined as tinyint.',
					$source,
				);
			}

			$columnChecks = $groupedChecks[$record['TABLE_SCHEMA']][$record['TABLE_NAME']][$record['COLUMN_NAME']] ?? [];

			$hasBooleanCheck = false;
			foreach ($columnChecks as $columnCheck) {
				// MySQL and MariaDB normalise the IN keyword's case differently, so match case-insensitively.
				if (
					stripos($columnCheck, "`{$record['COLUMN_NAME']}` in (0,1)") !== false
					|| stripos($columnCheck, "`{$record['COLUMN_NAME']}` in (1,0)") !== false
				) {
					$hasBooleanCheck = true;

					break; // No need to check others
				}
			}

			if (!$hasBooleanCheck) {
				$violations[] = new Violation(
					'bool_like_column',
					'Column '
					. $source->toString()
					. " contains only 0 and 1 but the table does not define CHECK ( `{$record['COLUMN_NAME']}` IN (0, 1)).",
					$source,
				);
			}
		}

		return new AnalysisResult($violations);
	}

	private function createProcedure(): void
	{
		// A run killed before cleanup() leaves the procedure behind; drop it first so CREATE never collides.
		$this->dbal->exec(
		/** @lang MySQL */
			'DROP PROCEDURE IF EXISTS OrisaiDbAudit_FindBoolLikeColumns;',
		);

		$this->dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE PROCEDURE OrisaiDbAudit_FindBoolLikeColumns()
BEGIN
	DECLARE fetched_table_schema VARCHAR(64) CHARACTER SET utf8mb4;
	DECLARE fetched_table_name VARCHAR(64) CHARACTER SET utf8mb4;
	DECLARE fetched_column_name VARCHAR(64) CHARACTER SET utf8mb4;
	DECLARE fetched_column_type VARCHAR(64) CHARACTER SET utf8mb4;
	DECLARE fetched_data_type VARCHAR(64) CHARACTER SET utf8mb4;

	DECLARE done TINYINT DEFAULT 0;

	-- Declare cursor to iterate over integer columns
	DECLARE cur CURSOR FOR
		SELECT c.TABLE_SCHEMA, c.TABLE_NAME, c.COLUMN_NAME, c.COLUMN_TYPE, c.DATA_TYPE
		FROM INFORMATION_SCHEMA.COLUMNS c
		JOIN INFORMATION_SCHEMA.TABLES t
			ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
		WHERE c.TABLE_SCHEMA = DATABASE()
			AND t.TABLE_TYPE = 'BASE TABLE'
			AND c.DATA_TYPE IN ('tinyint', 'smallint', 'mediumint', 'int', 'bigint')
		ORDER BY c.TABLE_SCHEMA, c.TABLE_NAME, c.COLUMN_NAME;

	-- Declare handler for cursor completion
	DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

	-- Create temporary table for results
	DROP TEMPORARY TABLE IF EXISTS OrisaiDbAudit_bool_like_columns;
	CREATE TEMPORARY TABLE OrisaiDbAudit_bool_like_columns (
		TABLE_SCHEMA VARCHAR(64) NOT NULL,
		TABLE_NAME VARCHAR(64) NOT NULL,
		COLUMN_NAME VARCHAR(64) NOT NULL,
		COLUMN_TYPE VARCHAR(64) NOT NULL,
		DATA_TYPE VARCHAR(64) NOT NULL
	) CHARACTER SET utf8mb4;

	OPEN cur;

	read_loop: LOOP
		FETCH cur INTO fetched_table_schema, fetched_table_name, fetched_column_name, fetched_column_type, fetched_data_type;
		IF done THEN
			LEAVE read_loop;
		END IF;

		-- Identifiers are backtick-quoted with embedded backticks doubled so quote-containing names do not break
		-- the dynamic SQL.
		SET @sql_query = CONCAT(
			'SELECT IF(COUNT(*) = 0, 1, 0) INTO @table_is_empty FROM `',
			REPLACE(fetched_table_schema, '`', '``'), '`.`', REPLACE(fetched_table_name, '`', '``'),
			'` LIMIT 1'
		);
		PREPARE stmt FROM @sql_query;
		EXECUTE stmt;
		DEALLOCATE PREPARE stmt;

		-- Skip this table as it is empty
		IF @table_is_empty = 1 THEN
			ITERATE read_loop;
		END IF;

		-- Count values outside {0, 1} and the number of non-NULL values. A column is bool-like only when every
		-- non-NULL value is 0 or 1 AND at least one non-NULL value exists; an all-NULL column is not bool-like
		-- because NULL NOT IN (0, 1) is never counted.
		SET @query = CONCAT(
			'SELECT SUM(CASE WHEN `', REPLACE(fetched_column_name, '`', '``'), '` NOT IN (0, 1) THEN 1 ELSE 0 END), COUNT(`',
			REPLACE(fetched_column_name, '`', '``'), '`) INTO @non_bool_count, @non_null_count FROM `',
			REPLACE(fetched_table_schema, '`', '``'), '`.`', REPLACE(fetched_table_name, '`', '``'), '`'
		);
		PREPARE stmt FROM @query;
		EXECUTE stmt;
		DEALLOCATE PREPARE stmt;

		IF @non_bool_count = 0 AND @non_null_count > 0 THEN
			INSERT INTO OrisaiDbAudit_bool_like_columns (TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, DATA_TYPE)
			VALUES (fetched_table_schema, fetched_table_name, fetched_column_name, fetched_column_type, fetched_data_type);
		END IF;
	END LOOP;

	CLOSE cur;

END
SQL,
		);
	}

	private function cleanup(): void
	{
		$this->dbal->exec(
		/** @lang MySQL */
			'DROP PROCEDURE IF EXISTS OrisaiDbAudit_FindBoolLikeColumns;',
		);

		$this->dbal->exec(
		/** @lang MySQL */
			'DROP TEMPORARY TABLE IF EXISTS OrisaiDbAudit_bool_like_columns;',
		);
	}

	/**
	 * @return list<array{
	 *     TABLE_SCHEMA: string,
	 *     TABLE_NAME: string,
	 *     COLUMN_NAME: string,
	 *     COLUMN_TYPE: string,
	 *     DATA_TYPE: string,
	 * }>
	 */
	private function getRecords(): array
	{
		$this->dbal->exec(
		/** @lang MySQL */
			'CALL OrisaiDbAudit_FindBoolLikeColumns();',
		);

		return $this->dbal->query(
			/** @lang MySQL */
			'SELECT * FROM OrisaiDbAudit_bool_like_columns ORDER BY TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME',
		);
	}

	/**
	 * @return list<array{
	 *     TABLE_SCHEMA: string,
	 *     TABLE_NAME: string,
	 *     COLUMN_NAME: string,
	 *     CHECK_CLAUSE: string,
	 * }>
	 */
	private function getChecks(): array
	{
		return $this->dbal->query(
			/** @lang MySQL */
			<<<'SQL'
SELECT
	c.TABLE_SCHEMA,
	c.TABLE_NAME,
	c.COLUMN_NAME,
	cc.CHECK_CLAUSE
FROM
	OrisaiDbAudit_bool_like_columns c
LEFT JOIN (
	SELECT
		tc.TABLE_SCHEMA,
		tc.TABLE_NAME,
		cc.CHECK_CLAUSE
	FROM
		INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
	JOIN INFORMATION_SCHEMA.CHECK_CONSTRAINTS cc
		ON tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
		AND tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA
	WHERE
		tc.CONSTRAINT_TYPE = 'CHECK'
) cc
	ON c.TABLE_SCHEMA = cc.TABLE_SCHEMA
	AND c.TABLE_NAME = cc.TABLE_NAME
	-- MariaDB stores CHECK_CLAUSE without the parentheses MySQL wraps around it, so match the bare backticked
	-- column name; the precise IN (0, 1) check is done in PHP.
	AND cc.CHECK_CLAUSE LIKE CONCAT('%`', c.COLUMN_NAME, '`%')
WHERE
	cc.CHECK_CLAUSE IS NOT NULL
ORDER BY
	c.TABLE_SCHEMA,
	c.TABLE_NAME,
	c.COLUMN_NAME;
SQL,
		);
	}

}

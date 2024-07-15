<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;
use function str_contains;

final class BoolLikeColumnMysqlAuditor extends BoolLikeColumnAuditor
{

	public function analyse(): array
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
					self::getKey(),
					'Column '
					. $source->toString()
					. ' contains only 0 and 1 but is not defined as tinyint.',
					$source,
				);
			}

			$columnChecks = $groupedChecks[$record['TABLE_SCHEMA']][$record['TABLE_NAME']][$record['COLUMN_NAME']] ?? [];

			$hasBooleanCheck = false;
			foreach ($columnChecks as $columnCheck) {
				if (
					str_contains($columnCheck, "`{$record['COLUMN_NAME']}` in (0,1)")
					|| str_contains($columnCheck, "`{$record['COLUMN_NAME']}` in (1,0)")
				) {
					$hasBooleanCheck = true;

					break; // No need to check others
				}
			}

			if (!$hasBooleanCheck) {
				$violations[] = new Violation(
					self::getKey(),
					'Column '
					. $source->toString()
					. " contains only 0 and 1 but the table does not define CHECK ( `{$record['COLUMN_NAME']}` IN (0, 1)).",
					$source,
				);
			}
		}

		return $violations;
	}

	private function createProcedure(): void
	{
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

	DECLARE non_bool_count TINYINT;

	DECLARE done TINYINT DEFAULT 0;

	-- Declare cursor to iterate over integer columns
	DECLARE cur CURSOR FOR
		SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, DATA_TYPE
		FROM INFORMATION_SCHEMA.COLUMNS
		WHERE TABLE_SCHEMA = DATABASE()
			AND DATA_TYPE IN ('tinyint', 'smallint', 'mediumint', 'int', 'bigint')
		ORDER BY TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME;

	-- Declare handler for cursor completion
	DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

	-- Create temporary table for results
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

		-- Check if the table is empty
		SET @sql_query = CONCAT(
			'SELECT IF(COUNT(*) = 0, 1, 0) INTO @table_is_empty FROM `', fetched_table_schema, '`.`', fetched_table_name, '` LIMIT 1'
		);
		PREPARE stmt FROM @sql_query;
		EXECUTE stmt;
		DEALLOCATE PREPARE stmt;

		-- Skip this table as it is empty
		IF @table_is_empty = 1 THEN
			ITERATE read_loop;
		END IF;

		-- Check if there is any record in the column with a value other than 0, 1 or null
		SET @query = CONCAT(
			'SELECT COUNT(1) INTO @non_bool_count FROM `', fetched_table_schema, '`.`', fetched_table_name,
			'` WHERE `', fetched_column_name, '` NOT IN (0, 1) LIMIT 1'
		);
		PREPARE stmt FROM @query;
		EXECUTE stmt;
		DEALLOCATE PREPARE stmt;

		-- If no rows are found with a value other than 0 or 1, add to result
		IF @non_bool_count = 0 THEN
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
			'DROP PROCEDURE OrisaiDbAudit_FindBoolLikeColumns;',
		);

		$this->dbal->exec(
		/** @lang MySQL */
			'DROP TEMPORARY TABLE OrisaiDbAudit_bool_like_columns;',
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
			'SELECT * FROM OrisaiDbAudit_bool_like_columns',
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
	AND cc.CHECK_CLAUSE LIKE CONCAT('%(`', c.COLUMN_NAME, '` in%')
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

<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Report\AnalysisResult;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;

final class EmptyColumnMysqlAuditor extends EmptyColumnAuditor
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
				'empty_column',
				'Column '
				. $source->toString()
				. ' is empty.',
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
			'DROP PROCEDURE IF EXISTS OrisaiDbAudit_FindEmptyColumns;',
		);

		$this->dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE PROCEDURE OrisaiDbAudit_FindEmptyColumns()
BEGIN
	DECLARE fetched_table_schema VARCHAR(64) CHARACTER SET utf8mb4;
	DECLARE fetched_table_name VARCHAR(64) CHARACTER SET utf8mb4;
	DECLARE fetched_column_name VARCHAR(64) CHARACTER SET utf8mb4;
	DECLARE fetched_data_type VARCHAR(64) CHARACTER SET utf8mb4;

	DECLARE table_is_empty TINYINT;
	DECLARE col_is_empty TINYINT;

	DECLARE done TINYINT DEFAULT 0;

	DECLARE cur CURSOR FOR
		SELECT c.TABLE_SCHEMA, c.TABLE_NAME, c.COLUMN_NAME, c.DATA_TYPE
		FROM information_schema.columns c
		JOIN information_schema.tables t
			ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
		WHERE c.TABLE_SCHEMA = DATABASE()
			AND t.TABLE_TYPE = 'BASE TABLE'
			AND (
				c.IS_NULLABLE = 'YES'
				OR c.DATA_TYPE IN ('char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext')
			)
		ORDER BY c.TABLE_SCHEMA, c.TABLE_NAME, c.COLUMN_NAME;

	DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

	DROP TEMPORARY TABLE IF EXISTS OrisaiDbAudit_empty_columns;
	CREATE TEMPORARY TABLE OrisaiDbAudit_empty_columns (
		TABLE_SCHEMA VARCHAR(64) NOT NULL,
		TABLE_NAME VARCHAR(64) NOT NULL,
		COLUMN_NAME VARCHAR(64) NOT NULL
	) CHARACTER SET utf8mb4;

	OPEN cur;

	read_loop: LOOP
		FETCH cur INTO fetched_table_schema, fetched_table_name, fetched_column_name, fetched_data_type;
		IF done THEN
			LEAVE read_loop;
		END IF;

		-- Identifiers are backtick-quoted with embedded backticks doubled so quote-containing names do not break
		-- the dynamic SQL.
		SET @empty_table_query = CONCAT(
			'SELECT IF(COUNT(*) = 0, 1, 0) INTO @table_is_empty FROM `',
			REPLACE(fetched_table_schema, '`', '``'), '`.`', REPLACE(fetched_table_name, '`', '``'),
			'` LIMIT 1'
		);
		PREPARE stmt FROM @empty_table_query;
		EXECUTE stmt;
		DEALLOCATE PREPARE stmt;

		-- Skip this table as it is empty
		IF @table_is_empty = 1 THEN
			ITERATE read_loop;
		END IF;

		-- A non-string column is empty only when every value is NULL; comparing it to '' would coerce a
		-- genuine 0 to empty. Only string columns additionally treat '' as empty.
		IF fetched_data_type IN ('char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext') THEN
			SET @empty_column_query = CONCAT(
				'SELECT IF(COUNT(*) = 0, 1, 0) INTO @col_is_empty FROM `',
				REPLACE(fetched_table_schema, '`', '``'), '`.`', REPLACE(fetched_table_name, '`', '``'),
				'` WHERE `', REPLACE(fetched_column_name, '`', '``'), '` IS NOT NULL AND `',
				REPLACE(fetched_column_name, '`', '``'), '` != \'\' LIMIT 1'
			);
		ELSE
			SET @empty_column_query = CONCAT(
				'SELECT IF(COUNT(*) = 0, 1, 0) INTO @col_is_empty FROM `',
				REPLACE(fetched_table_schema, '`', '``'), '`.`', REPLACE(fetched_table_name, '`', '``'),
				'` WHERE `', REPLACE(fetched_column_name, '`', '``'), '` IS NOT NULL LIMIT 1'
			);
		END IF;

		-- Prepare and execute the dynamic SQL
		PREPARE stmt FROM @empty_column_query;
		EXECUTE stmt;
		DEALLOCATE PREPARE stmt;

		-- Check the result
		IF @col_is_empty = 1 THEN
			-- Insert the result into the temporary table
			INSERT INTO OrisaiDbAudit_empty_columns (TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME)
			VALUES (fetched_table_schema, fetched_table_name, fetched_column_name);
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
			'DROP PROCEDURE IF EXISTS OrisaiDbAudit_FindEmptyColumns;',
		);

		$this->dbal->exec(
		/** @lang MySQL */
			'DROP TEMPORARY TABLE IF EXISTS OrisaiDbAudit_empty_columns;',
		);
	}

	/**
	 * @return list<array{
	 *     TABLE_SCHEMA: string,
	 *     TABLE_NAME: string,
	 *     COLUMN_NAME: string,
	 * }>
	 */
	private function getRecords(): array
	{
		$this->dbal->exec(
		/** @lang MySQL */
			'CALL OrisaiDbAudit_FindEmptyColumns();',
		);

		return $this->dbal->query(
			/** @lang MySQL */
			'SELECT * FROM OrisaiDbAudit_empty_columns ORDER BY TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME',
		);
	}

}

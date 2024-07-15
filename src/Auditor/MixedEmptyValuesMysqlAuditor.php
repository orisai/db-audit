<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Report\AnalysisResult;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;

final class MixedEmptyValuesMysqlAuditor extends MixedEmptyValuesAuditor
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
				'mixed_empty_values',
				'Column '
				. $source->toString()
				. ' contains mixed empty values.',
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
			'DROP PROCEDURE IF EXISTS OrisaiDbAudit_FindMixedEmptyColumns;',
		);

		$this->dbal->exec(
			/** @lang MySQL */
			<<<'SQL'
CREATE PROCEDURE OrisaiDbAudit_FindMixedEmptyColumns()
BEGIN
	DECLARE fetched_table_schema VARCHAR(64) CHARACTER SET utf8mb4;
	DECLARE fetched_table_name VARCHAR(64) CHARACTER SET utf8mb4;
	DECLARE fetched_column_name VARCHAR(64) CHARACTER SET utf8mb4;
	DECLARE fetched_column_type VARCHAR(64) CHARACTER SET utf8mb4;

	DECLARE done TINYINT DEFAULT 0;

	DECLARE cur CURSOR FOR
		SELECT c.TABLE_SCHEMA, c.TABLE_NAME, c.COLUMN_NAME, c.COLUMN_TYPE
		FROM INFORMATION_SCHEMA.COLUMNS c
		JOIN INFORMATION_SCHEMA.TABLES t
			ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
		WHERE c.TABLE_SCHEMA = DATABASE()
			AND t.TABLE_TYPE = 'BASE TABLE'
			AND c.DATA_TYPE IN ('char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext')
			AND c.IS_NULLABLE = 'YES'
		ORDER BY c.TABLE_SCHEMA, c.TABLE_NAME, c.COLUMN_NAME;

	DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

	DROP TEMPORARY TABLE IF EXISTS OrisaiDbAudit_mixed_empty_columns;
	CREATE TEMPORARY TABLE OrisaiDbAudit_mixed_empty_columns (
		TABLE_SCHEMA VARCHAR(64),
		TABLE_NAME VARCHAR(64),
		COLUMN_NAME VARCHAR(64),
		COLUMN_TYPE VARCHAR(64)
	) CHARACTER SET utf8mb4;

	OPEN cur;

	read_loop: LOOP
		FETCH cur INTO fetched_table_schema, fetched_table_name, fetched_column_name, fetched_column_type;
		IF done THEN
			LEAVE read_loop;
		END IF;

		-- Identifiers are backtick-quoted with embedded backticks doubled so quote-containing names do not break
		-- the dynamic SQL.
		SET @query_empty_string = CONCAT(
			'SELECT COUNT(*) INTO @empty_string_exists FROM `',
			REPLACE(fetched_table_schema, '`', '``'), '`.`', REPLACE(fetched_table_name, '`', '``'),
			'` WHERE `', REPLACE(fetched_column_name, '`', '``'), '` = \'\' LIMIT 1'
		);
		PREPARE stmt_empty_string FROM @query_empty_string;
		EXECUTE stmt_empty_string;
		DEALLOCATE PREPARE stmt_empty_string;

		IF @empty_string_exists > 0 THEN
			-- Check for null value
			SET @query_null_value = CONCAT(
				'SELECT COUNT(*) INTO @null_value_exists FROM `',
				REPLACE(fetched_table_schema, '`', '``'), '`.`', REPLACE(fetched_table_name, '`', '``'),
				'` WHERE `', REPLACE(fetched_column_name, '`', '``'), '` IS NULL LIMIT 1'
			);
			PREPARE stmt_null_value FROM @query_null_value;
			EXECUTE stmt_null_value;
			DEALLOCATE PREPARE stmt_null_value;

			IF @null_value_exists > 0 THEN
				INSERT INTO OrisaiDbAudit_mixed_empty_columns (TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, COLUMN_TYPE)
				VALUES (fetched_table_schema, fetched_table_name, fetched_column_name, fetched_column_type);
			END IF;
		END IF;
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
			'DROP PROCEDURE IF EXISTS OrisaiDbAudit_FindMixedEmptyColumns;',
		);

		$this->dbal->exec(
			/** @lang MySQL */
			'DROP TEMPORARY TABLE IF EXISTS OrisaiDbAudit_mixed_empty_columns;',
		);
	}

	/**
	 * @return list<array{
	 *     TABLE_SCHEMA: string,
	 *     TABLE_NAME: string,
	 *     COLUMN_NAME: string,
	 *     COLUMN_TYPE: string,
	 * }>
	 */
	private function getRecords(): array
	{
		$this->dbal->exec(
		/** @lang MySQL */
			'CALL OrisaiDbAudit_FindMixedEmptyColumns();',
		);

		return $this->dbal->query(
		/** @lang MySQL */
			'SELECT * FROM OrisaiDbAudit_mixed_empty_columns ORDER BY TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME',
		);
	}

}

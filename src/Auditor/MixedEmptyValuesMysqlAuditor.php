<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;

final class MixedEmptyValuesMysqlAuditor extends MixedEmptyValuesAuditor
{

	public function analyse(): array
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
				self::getKey(),
				'Column '
				. $source->toString()
				. ' contains mixed empty values.',
				$source,
			);
		}

		return $violations;
	}

	private function createProcedure(): void
	{
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

	DECLARE empty_string_exists INT DEFAULT 0;
	DECLARE null_value_exists INT DEFAULT 0;

	DECLARE cur CURSOR FOR
		SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, COLUMN_TYPE
		FROM INFORMATION_SCHEMA.COLUMNS
		WHERE TABLE_SCHEMA = DATABASE()
			AND DATA_TYPE IN ('varchar', 'tinytext', 'text', 'mediumtext', 'longtext')
			AND IS_NULLABLE = 'YES'
		ORDER BY TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME;

	DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

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

		-- Check for empty string
		SET @query_empty_string = CONCAT(
			'SELECT COUNT(*) INTO @empty_string_exists FROM `', fetched_table_schema, '`.`', fetched_table_name,
			'` WHERE `', fetched_column_name, '` = \'\' LIMIT 1'
		);
		PREPARE stmt_empty_string FROM @query_empty_string;
		EXECUTE stmt_empty_string;
		DEALLOCATE PREPARE stmt_empty_string;

		IF @empty_string_exists > 0 THEN
			-- Check for null value
			SET @query_null_value = CONCAT(
				'SELECT COUNT(*) INTO @null_value_exists FROM `', fetched_table_schema, '`.`', fetched_table_name,
				'` WHERE `', fetched_column_name, '` IS NULL LIMIT 1'
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
			'DROP PROCEDURE OrisaiDbAudit_FindMixedEmptyColumns;',
		);

		$this->dbal->exec(
			/** @lang MySQL */
			'DROP TEMPORARY TABLE OrisaiDbAudit_mixed_empty_columns;',
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
			'SELECT * FROM OrisaiDbAudit_mixed_empty_columns',
		);
	}

}

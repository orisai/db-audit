<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;

final class NullableWithNoNullsMysqlAuditor extends NullableWithNoNullsAuditor
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
				. ' is nullable but contains no nulls.',
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
CREATE PROCEDURE OrisaiDbAudit_FindNonNullableColumns()
BEGIN
	DECLARE fetched_table_schema VARCHAR(64) CHARACTER SET utf8mb4;
	DECLARE fetched_table_name VARCHAR(64) CHARACTER SET utf8mb4;
	DECLARE fetched_column_name VARCHAR(64) CHARACTER SET utf8mb4;
	DECLARE fetched_column_type VARCHAR(64) CHARACTER SET utf8mb4;

	DECLARE done INT DEFAULT 0;

	DECLARE cur CURSOR FOR
		SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, COLUMN_TYPE
		FROM INFORMATION_SCHEMA.COLUMNS
		WHERE TABLE_SCHEMA = DATABASE()
			AND IS_NULLABLE = 'YES'
		ORDER BY TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME;

	DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

	-- Create the temporary table
	CREATE TEMPORARY TABLE OrisaiDbAudit_nullable_with_no_nulls (
		TABLE_SCHEMA VARCHAR(64) NOT NULL,
		TABLE_NAME VARCHAR(64) NOT NULL,
		COLUMN_NAME VARCHAR(64) NOT NULL,
		COLUMN_TYPE VARCHAR(64) NOT NULL
	) CHARACTER SET utf8mb4;

	OPEN cur;

	read_loop: LOOP
		FETCH cur INTO fetched_table_schema, fetched_table_name, fetched_column_name, fetched_column_type;
		IF done THEN
			LEAVE read_loop;
		END IF;

		-- Check if the table is empty
		SET @empty_table_query = CONCAT(
			'SELECT IF(COUNT(*) = 0, 1, 0) INTO @table_is_empty FROM `', fetched_table_schema, '`.`', fetched_table_name, '` LIMIT 1'
		);
		PREPARE stmt FROM @empty_table_query;
		EXECUTE stmt;
		DEALLOCATE PREPARE stmt;

		-- Skip this table as it is empty
		IF @table_is_empty = 1 THEN
			ITERATE read_loop;
		END IF;

		-- Check if the column contains null values
		SET @checkColumnQuery = CONCAT(
			'SELECT IF(COUNT(*) = 0, 1, 0) INTO @nullNotFound FROM `', fetched_table_schema, '`.`', fetched_table_name,
			'` WHERE `', fetched_column_name, '` IS NULL LIMIT 1'
		);
		PREPARE stmt FROM @checkColumnQuery;
		EXECUTE stmt;
		DEALLOCATE PREPARE stmt;

		-- Insert the column information into the temporary table if no null values are found
		IF @nullNotFound = 1 THEN
			INSERT INTO OrisaiDbAudit_nullable_with_no_nulls (TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, COLUMN_TYPE)
			VALUES (fetched_table_schema, fetched_table_name, fetched_column_name, fetched_column_type);
		END IF;
	END LOOP read_loop;

	CLOSE cur;
END;
SQL,
		);
	}

	private function cleanup(): void
	{
		$this->dbal->exec(
		/** @lang MySQL */
			'DROP PROCEDURE OrisaiDbAudit_FindNonNullableColumns;',
		);

		$this->dbal->exec(
		/** @lang MySQL */
			'DROP TEMPORARY TABLE OrisaiDbAudit_nullable_with_no_nulls;',
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
			'CALL OrisaiDbAudit_FindNonNullableColumns();',
		);

		return $this->dbal->query(
		/** @lang MySQL */
			'SELECT * FROM OrisaiDbAudit_nullable_with_no_nulls',
		);
	}

}

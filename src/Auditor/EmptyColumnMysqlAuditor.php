<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;

final class EmptyColumnMysqlAuditor extends EmptyColumnAuditor
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
				. ' is empty.',
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
CREATE PROCEDURE OrisaiDbAudit_FindEmptyColumns()
BEGIN
	DECLARE fetched_table_schema VARCHAR(64) CHARACTER SET utf8mb4;
	DECLARE fetched_table_name VARCHAR(64) CHARACTER SET utf8mb4;
	DECLARE fetched_column_name VARCHAR(64) CHARACTER SET utf8mb4;

	DECLARE table_is_empty TINYINT;
	DECLARE col_is_empty TINYINT;

	DECLARE done TINYINT DEFAULT 0;

	DECLARE cur CURSOR FOR
		SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME
		FROM information_schema.columns
		WHERE TABLE_SCHEMA = DATABASE()
			AND (
				IS_NULLABLE = 'YES'
				OR DATA_TYPE IN ('varchar', 'tinytext', 'text', 'mediumtext', 'longtext')
			)
		ORDER BY TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME;

	DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

	CREATE TEMPORARY TABLE OrisaiDbAudit_empty_columns (
		TABLE_SCHEMA VARCHAR(64) NOT NULL,
		TABLE_NAME VARCHAR(64) NOT NULL,
		COLUMN_NAME VARCHAR(64) NOT NULL
	) CHARACTER SET utf8mb4;

	OPEN cur;

	read_loop: LOOP
		FETCH cur INTO fetched_table_schema, fetched_table_name, fetched_column_name;
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

		-- Construct the query to check if the column contains only NULL or empty strings
		SET @empty_column_query = CONCAT(
			'SELECT IF(COUNT(*) = 0, 1, 0) INTO @col_is_empty FROM `', fetched_table_schema, '`.`', fetched_table_name,
			'` WHERE `', fetched_column_name, '` IS NOT NULL AND `', fetched_column_name, '` != "" LIMIT 1'
		);

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
			'DROP PROCEDURE OrisaiDbAudit_FindEmptyColumns;',
		);

		$this->dbal->exec(
		/** @lang MySQL */
			'DROP TEMPORARY TABLE OrisaiDbAudit_empty_columns;',
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
			'SELECT * FROM OrisaiDbAudit_empty_columns',
		);
	}

}

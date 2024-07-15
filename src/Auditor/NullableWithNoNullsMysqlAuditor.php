<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Change\ColumnTargetChange;
use Orisai\DbAudit\Report\AnalysisResult;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;
use function is_string;

final class NullableWithNoNullsMysqlAuditor extends NullableWithNoNullsAuditor
{

	public function analyse(): AnalysisResult
	{
		$this->createProcedure();

		try {
			$records = $this->getRecords();
		} finally {
			$this->cleanup();
		}

		$details = [];
		foreach ($this->getNullableColumns() as $column) {
			$table = $column['TABLE_NAME'] ?? null;
			$name = $column['COLUMN_NAME'] ?? null;
			if (is_string($table) && is_string($name)) {
				$details[$table . "\0" . $name] = $column;
			}
		}

		$violations = [];
		foreach ($records as $record) {
			$source = new ColumnViolationSource(
				$record['TABLE_SCHEMA'],
				null,
				$record['TABLE_NAME'],
				$record['COLUMN_NAME'],
			);

			$detail = $details[$record['TABLE_NAME'] . "\0" . $record['COLUMN_NAME']] ?? null;
			$change = $detail === null
				? null
				: $this->planFix($record['TABLE_SCHEMA'], $record['TABLE_NAME'], $record['COLUMN_NAME'], $detail);

			$violations[] = new Violation(
				'nullable_with_no_nulls',
				'Column '
				. $source->toString()
				. ' is nullable but contains no nulls.',
				$source,
				$change !== null,
				$change !== null ? 'Tighten the column to NOT NULL.' : null,
				$change !== null ? [$change] : [],
			);
		}

		return new AnalysisResult($violations);
	}

	/**
	 * The tightening is emitted only for plain columns — no explicit default and no extra attribute
	 * (auto_increment, generated, ON UPDATE); anything else stays a non-fixable finding. The change is a single
	 * `setNullable(false)` delta: the type, charset/collation and comment come from the current definition via the
	 * planner's merge, so tightening a commented column no longer drops its comment.
	 *
	 * @param array<string, mixed> $detail
	 */
	private function planFix(string $database, string $table, string $column, array $detail): ?ColumnTargetChange
	{
		$type = $detail['COLUMN_TYPE'] ?? null;
		$extra = $detail['EXTRA'] ?? null;
		// MariaDB reports a defaultless nullable column's COLUMN_DEFAULT as the string 'NULL', MySQL as real null;
		// both mean "no default to preserve". Any other value is an explicit default -> not safe to tighten, skip.
		$default = $detail['COLUMN_DEFAULT'] ?? null;
		$hasExplicitDefault = $default !== null && $default !== 'NULL';
		if ($hasExplicitDefault || $extra !== '' || !is_string($type)) {
			return null;
		}

		return ColumnTargetChange::forColumn($database, $table, $column)->setNullable(false);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function getNullableColumns(): array
	{
		return $this->dbal->query(
		/** @lang MySQL */
			<<<'SQL'
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_DEFAULT, EXTRA
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND IS_NULLABLE = 'YES'
SQL,
		);
	}

	private function createProcedure(): void
	{
		// A run killed before cleanup() leaves the procedure behind; drop it first so CREATE never collides.
		$this->dbal->exec(
		/** @lang MySQL */
			'DROP PROCEDURE IF EXISTS OrisaiDbAudit_FindNonNullableColumns;',
		);

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
		SELECT c.TABLE_SCHEMA, c.TABLE_NAME, c.COLUMN_NAME, c.COLUMN_TYPE
		FROM INFORMATION_SCHEMA.COLUMNS c
		JOIN INFORMATION_SCHEMA.TABLES t
			ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
		WHERE c.TABLE_SCHEMA = DATABASE()
			AND t.TABLE_TYPE = 'BASE TABLE'
			AND c.IS_NULLABLE = 'YES'
		ORDER BY c.TABLE_SCHEMA, c.TABLE_NAME, c.COLUMN_NAME;

	DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

	-- Create the temporary table
	DROP TEMPORARY TABLE IF EXISTS OrisaiDbAudit_nullable_with_no_nulls;
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

		-- Check if the column contains null values
		SET @checkColumnQuery = CONCAT(
			'SELECT IF(COUNT(*) = 0, 1, 0) INTO @nullNotFound FROM `',
			REPLACE(fetched_table_schema, '`', '``'), '`.`', REPLACE(fetched_table_name, '`', '``'),
			'` WHERE `', REPLACE(fetched_column_name, '`', '``'), '` IS NULL LIMIT 1'
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
			'DROP PROCEDURE IF EXISTS OrisaiDbAudit_FindNonNullableColumns;',
		);

		$this->dbal->exec(
		/** @lang MySQL */
			'DROP TEMPORARY TABLE IF EXISTS OrisaiDbAudit_nullable_with_no_nulls;',
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
			'SELECT * FROM OrisaiDbAudit_nullable_with_no_nulls ORDER BY TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME',
		);
	}

}

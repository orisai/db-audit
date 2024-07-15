<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;

final class ForeignKeyViolationMysqlAuditor extends ForeignKeyViolationAuditor
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

			$referencedSource = new ColumnViolationSource(
				$record['REFERENCED_TABLE_SCHEMA'],
				null,
				$record['REFERENCED_TABLE_NAME'],
				$record['REFERENCED_COLUMN_NAME'],
			);

			$violations[] = new Violation(
				self::getKey(),
				'Foreign key of column '
				. $source->toString()
				. ' references column '
				. $referencedSource->toString()
				. ' but some of the referenced records do not exist.',
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
CREATE PROCEDURE OrisaiDbAudit_FindForeignKeyViolations()
BEGIN
	DECLARE fetched_table_schema VARCHAR(64) CHARACTER SET utf8mb4;
	DECLARE fetched_table_name VARCHAR(64) CHARACTER SET utf8mb4;
	DECLARE fetched_column_name VARCHAR(64) CHARACTER SET utf8mb4;
	DECLARE fetched_referenced_table_schema VARCHAR(64) CHARACTER SET utf8mb4;
	DECLARE fetched_referenced_table_name VARCHAR(64) CHARACTER SET utf8mb4;
	DECLARE fetched_referenced_column_name VARCHAR(64) CHARACTER SET utf8mb4;

	DECLARE done INT DEFAULT 0;

	DECLARE cur CURSOR FOR
	SELECT
		TABLE_SCHEMA,
		TABLE_NAME,
		COLUMN_NAME,
		REFERENCED_TABLE_SCHEMA,
		REFERENCED_TABLE_NAME,
		REFERENCED_COLUMN_NAME
	FROM OrisaiDbAudit_valid_foreign_keys
	ORDER BY TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME;

	DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

	CREATE TEMPORARY TABLE OrisaiDbAudit_valid_foreign_keys (
		TABLE_SCHEMA VARCHAR(64) NOT NULL,
		TABLE_NAME VARCHAR(64) NOT NULL,
		COLUMN_NAME VARCHAR(64) NOT NULL,
		REFERENCED_TABLE_SCHEMA VARCHAR(64) NOT NULL,
		REFERENCED_TABLE_NAME VARCHAR(64) NOT NULL,
		REFERENCED_COLUMN_NAME VARCHAR(64) NOT NULL
	) CHARACTER SET utf8mb4;

	CREATE TEMPORARY TABLE OrisaiDbAudit_foreign_key_violations (
		TABLE_SCHEMA VARCHAR(64) NOT NULL,
		TABLE_NAME VARCHAR(64) NOT NULL,
		COLUMN_NAME VARCHAR(64) NOT NULL,
		REFERENCED_TABLE_SCHEMA VARCHAR(64) NOT NULL,
		REFERENCED_TABLE_NAME VARCHAR(64) NOT NULL,
		REFERENCED_COLUMN_NAME VARCHAR(64) NOT NULL
	) CHARACTER SET utf8mb4;

	-- Insert valid foreign key references
	INSERT INTO OrisaiDbAudit_valid_foreign_keys
	SELECT
		kcu.TABLE_SCHEMA,
		kcu.TABLE_NAME,
		kcu.COLUMN_NAME,
		kcu.REFERENCED_TABLE_SCHEMA,
		kcu.REFERENCED_TABLE_NAME,
		kcu.REFERENCED_COLUMN_NAME
	FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
	JOIN INFORMATION_SCHEMA.TABLES rt
	-- Excludes non-existent tables
	ON kcu.REFERENCED_TABLE_SCHEMA = rt.TABLE_SCHEMA AND kcu.REFERENCED_TABLE_NAME = rt.TABLE_NAME
	WHERE kcu.TABLE_SCHEMA = DATABASE()
		AND kcu.REFERENCED_TABLE_SCHEMA IS NOT NULL;

	OPEN cur;

	read_loop: LOOP
		FETCH cur INTO
			fetched_table_schema,
			fetched_table_name,
			fetched_column_name,
			fetched_referenced_table_schema,
			fetched_referenced_table_name,
			fetched_referenced_column_name;
		IF done THEN
			LEAVE read_loop;
		END IF;

		SET @check_query = CONCAT('
			SELECT IF(COUNT(*) > 0, 1, 0) INTO @has_invalid_reference
			FROM `', fetched_table_schema, '`.`', fetched_table_name, '` t
			LEFT JOIN `', fetched_referenced_table_schema, '`.`', fetched_referenced_table_name, '` r
			ON t.`', fetched_column_name, '` = r.`', fetched_referenced_column_name, '`
			WHERE t.`', fetched_column_name, '` IS NOT NULL AND r.`', fetched_referenced_column_name, '` IS NULL
			LIMIT 1
		');

		PREPARE stmt FROM @check_query;
		EXECUTE stmt;
		DEALLOCATE PREPARE stmt;

		IF @has_invalid_reference = 1 THEN
			INSERT INTO OrisaiDbAudit_foreign_key_violations (
				TABLE_SCHEMA,
				TABLE_NAME,
				COLUMN_NAME,
				REFERENCED_TABLE_SCHEMA,
				REFERENCED_TABLE_NAME,
				REFERENCED_COLUMN_NAME
			) VALUES (
				fetched_table_schema,
				fetched_table_name,
				fetched_column_name,
				fetched_referenced_table_schema,
				fetched_referenced_table_name,
				fetched_referenced_column_name
			);
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
			'DROP PROCEDURE OrisaiDbAudit_FindForeignKeyViolations;',
		);

		$this->dbal->exec(
		/** @lang MySQL */
			'DROP TEMPORARY TABLE OrisaiDbAudit_valid_foreign_keys;',
		);

		$this->dbal->exec(
		/** @lang MySQL */
			'DROP TEMPORARY TABLE OrisaiDbAudit_foreign_key_violations;',
		);
	}

	/**
	 * @return list<array{
	 *     TABLE_SCHEMA: string,
	 *     TABLE_NAME: string,
	 *     COLUMN_NAME: string,
	 *     REFERENCED_TABLE_SCHEMA: string,
	 *     REFERENCED_TABLE_NAME: string,
	 *     REFERENCED_COLUMN_NAME: string,
	 * }>
	 */
	private function getRecords(): array
	{
		$this->dbal->exec(
		/** @lang MySQL */
			'CALL OrisaiDbAudit_FindForeignKeyViolations();',
		);

		return $this->dbal->query(
		/** @lang MySQL */
			'SELECT * FROM OrisaiDbAudit_foreign_key_violations;',
		);
	}

}

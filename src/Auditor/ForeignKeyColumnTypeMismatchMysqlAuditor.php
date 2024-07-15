<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;

final class ForeignKeyColumnTypeMismatchMysqlAuditor extends ForeignKeyColumnTypeMismatchAuditor
{

	public function analyse(): array
	{
		$records = $this->getRecords();

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

			if ($record['COLUMN_CHARACTER_SET'] !== $record['REFERENCED_COLUMN_CHARACTER_SET']) {
				$violations[] = new Violation(
					self::getKey(),
					'Column '
					. $source->toString()
					. ' references column '
					. $referencedSource->toString()
					. ' but the character set does not match.',
					$source,
				);
			}

			if ($record['COLUMN_TYPE'] !== $record['REFERENCED_COLUMN_TYPE']) {
				$violations[] = new Violation(
					self::getKey(),
					'Column '
					. $source->toString()
					. ' references column '
					. $referencedSource->toString()
					. ' but the column size does not match.',
					$source,
				);
			}
		}

		return $violations;
	}

	/**
	 * @return list<array{
	 *     TABLE_SCHEMA: string,
	 *     TABLE_NAME: string,
	 *     COLUMN_NAME: string,
	 *     COLUMN_TYPE: string,
	 *     COLUMN_CHARACTER_SET: string,
	 *     REFERENCED_TABLE_SCHEMA: string,
	 *     REFERENCED_TABLE_NAME: string,
	 *     REFERENCED_COLUMN_NAME: string,
	 *     REFERENCED_COLUMN_TYPE: string,
	 *     REFERENCED_COLUMN_CHARACTER_SET: string,
	 * }>
	 */
	private function getRecords(): array
	{
		return $this->dbal->query(
		/** @lang MySQL */
			<<<'SQL'
SELECT
	kcu.TABLE_SCHEMA,
	kcu.TABLE_NAME,
	kcu.COLUMN_NAME,
	kcu.REFERENCED_TABLE_SCHEMA,
	kcu.REFERENCED_TABLE_NAME,
	kcu.REFERENCED_COLUMN_NAME,
	tc.COLUMN_TYPE AS COLUMN_TYPE,
	trc.COLUMN_TYPE AS REFERENCED_COLUMN_TYPE,
	tc.CHARACTER_SET_NAME AS COLUMN_CHARACTER_SET,
	trc.CHARACTER_SET_NAME AS REFERENCED_COLUMN_CHARACTER_SET
FROM
	INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
JOIN INFORMATION_SCHEMA.COLUMNS tc
	ON kcu.TABLE_SCHEMA = tc.TABLE_SCHEMA
	AND kcu.TABLE_NAME = tc.TABLE_NAME
	AND kcu.COLUMN_NAME = tc.COLUMN_NAME
JOIN INFORMATION_SCHEMA.COLUMNS trc
	ON kcu.REFERENCED_TABLE_SCHEMA = trc.TABLE_SCHEMA
	AND kcu.REFERENCED_TABLE_NAME = trc.TABLE_NAME
	AND kcu.REFERENCED_COLUMN_NAME = trc.COLUMN_NAME
WHERE
	kcu.TABLE_SCHEMA = DATABASE()
	AND kcu.REFERENCED_TABLE_SCHEMA IS NOT NULL
	AND (
		tc.COLUMN_TYPE != trc.COLUMN_TYPE
		OR tc.CHARACTER_SET_NAME != trc.CHARACTER_SET_NAME
	)
ORDER BY
	kcu.TABLE_SCHEMA,
	kcu.TABLE_NAME,
	kcu.COLUMN_NAME;
SQL,
		);
	}

}

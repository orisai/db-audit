<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;

final class ForeignKeyReferencedColumnExistenceMysqlAuditor extends ForeignKeyReferencedColumnExistenceAuditor
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

			$violations[] = new Violation(
				self::getKey(),
				'Foreign key of column '
				. $source->toString()
				. ' references column '
				. $referencedSource->toString()
				. ' but the referenced table does not exist.',
				$source,
			);
		}

		return $violations;
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
		return $this->dbal->query(
		/** @lang MySQL */
			<<<'SQL'
SELECT
	kcu.TABLE_SCHEMA,
	kcu.TABLE_NAME,
	kcu.COLUMN_NAME,
	kcu.REFERENCED_TABLE_SCHEMA,
	kcu.REFERENCED_TABLE_NAME,
	kcu.REFERENCED_COLUMN_NAME
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
LEFT JOIN INFORMATION_SCHEMA.TABLES rt
	ON kcu.REFERENCED_TABLE_SCHEMA = rt.TABLE_SCHEMA AND kcu.REFERENCED_TABLE_NAME = rt.TABLE_NAME
WHERE kcu.TABLE_SCHEMA = DATABASE()
	AND kcu.REFERENCED_TABLE_SCHEMA IS NOT NULL
	AND rt.TABLE_NAME IS NULL
ORDER BY kcu.TABLE_SCHEMA, kcu.TABLE_NAME, kcu.COLUMN_NAME;
SQL,
		);
	}

}

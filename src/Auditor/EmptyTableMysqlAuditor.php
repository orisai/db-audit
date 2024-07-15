<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Report\TableViolationSource;
use Orisai\DbAudit\Report\Violation;

final class EmptyTableMysqlAuditor extends EmptyTableAuditor
{

	public function analyse(): array
	{
		//TODO - zkontrolovat kde by se mělo dotazovat jen na tabulky, bez views
		//		- a taky ignorovat temporary tables
		$records = $this->getRecords();

		$violations = [];
		foreach ($records as $record) {
			$source = new TableViolationSource(
				$record['TABLE_SCHEMA'],
				null,
				$record['TABLE_NAME'],
			);

			$violations[] = new Violation(
				self::getKey(),
				'Table '
				. $source->toString()
				. ' is empty.',
				$source,
			);
		}

		return $violations;
	}

	/**
	 * @return list<array{
	 *     TABLE_SCHEMA: string,
	 *     TABLE_NAME: string,
	 * }>
	 */
	private function getRecords(): array
	{
		return $this->dbal->query(
		/** @lang MySQL */
			<<<'SQL'
SELECT
	TABLE_SCHEMA,
	TABLE_NAME
FROM
	INFORMATION_SCHEMA.TABLES
WHERE
	TABLE_SCHEMA = DATABASE()
	AND TABLE_ROWS = 0
	AND TABLE_TYPE = 'BASE TABLE'
ORDER BY TABLE_SCHEMA, TABLE_NAME;
SQL,
		);
	}

}

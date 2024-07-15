<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Collation\TableNameFilter;
use Orisai\DbAudit\Report\AnalysisResult;
use Orisai\DbAudit\Report\TableViolationSource;
use Orisai\DbAudit\Report\Violation;
use Orisai\DbAudit\Schema\ColumnCharsetClass;
use Orisai\DbAudit\Schema\SchemaRequest;

final class MissingPrimaryKeyMysqlAuditor extends MissingPrimaryKeyAuditor
{

	public function getSchemaRequest(): SchemaRequest
	{
		return new SchemaRequest(
			ColumnCharsetClass::any(),
			new TableNameFilter(),
			false,
			true,
			true,
		);
	}

	public function analyse(): AnalysisResult
	{
		$this->refreshOwnedSchema();

		$db = $this->schema->getDatabaseDefault()['name'];
		$statisticsByTable = $this->schema->getStatisticsByTable();

		$violations = [];
		foreach ($this->schema->getTables() as $table) {
			$tableName = $table['TABLE_NAME'];

			$hasPrimary = false;
			foreach ($statisticsByTable[$tableName] ?? [] as $stat) {
				if ($stat['INDEX_NAME'] === 'PRIMARY') {
					$hasPrimary = true;

					break;
				}
			}

			if ($hasPrimary) {
				continue;
			}

			$source = new TableViolationSource($db, null, $tableName);
			$violations[] = new Violation(
				'missing_primary_key',
				'Table ' . $source->toString() . ' has no primary key.',
				$source,
				false,
				'Add a PRIMARY KEY (or a stable surrogate key).',
			);
		}

		return new AnalysisResult($violations);
	}

}

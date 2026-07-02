<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Change\TableEngineChange;
use Orisai\DbAudit\Report\AnalysisResult;
use Orisai\DbAudit\Report\TableViolationSource;
use Orisai\DbAudit\Report\Violation;
use Orisai\DbAudit\Schema\ColumnCharsetClass;
use Orisai\DbAudit\Schema\SchemaRequest;
use Orisai\DbAudit\Schema\TableExclude;

final class NonTransactionalEngineMysqlAuditor extends NonTransactionalEngineAuditor
{

	public function getSchemaRequest(): SchemaRequest
	{
		return new SchemaRequest(
			ColumnCharsetClass::any(),
			new TableExclude(),
			false,
			false,
			true,
		);
	}

	public function analyse(): AnalysisResult
	{
		$this->refreshOwnedSchema();

		$db = $this->schema->getDatabaseDefault()['name'];

		$violations = [];
		foreach ($this->schema->getTables() as $table) {
			$engine = (string) $table['ENGINE'];
			if ($engine === 'InnoDB') {
				continue;
			}

			$source = new TableViolationSource($db, null, $table['TABLE_NAME']);
			$violations[] = new Violation(
				'non_transactional_engine',
				'Table ' . $source->toString()
				. " uses non-transactional storage engine '" . $engine . "'.",
				$source,
				true,
				'Convert the table to InnoDB for transactions, foreign keys and crash safety.',
				[new TableEngineChange($db, $table['TABLE_NAME'], 'InnoDB')],
			);
		}

		return new AnalysisResult($violations);
	}

}

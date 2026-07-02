<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Analyser;
use Orisai\DbAudit\AnalyserCategory;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Schema\SchemaProvider;
use Orisai\DbAudit\Schema\SchemaRequesting;

abstract class NonTransactionalEngineAuditor implements Analyser, SchemaRequesting
{

	use MysqlFamilySupport;

	protected DbalAdapter $dbal;

	protected SchemaProvider $schema;

	public function __construct(SchemaProvider $schema)
	{
		$this->schema = $schema;
		$this->dbal = $schema->getDbal();
	}

	public function getCategory(): AnalyserCategory
	{
		return AnalyserCategory::structure();
	}

}

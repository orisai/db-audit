<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Analyser;
use Orisai\DbAudit\AnalyserCategory;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Schema\SchemaProvider;
use Orisai\DbAudit\Schema\SchemaRequesting;

abstract class ForeignKeyColumnNameMismatchAuditor implements Analyser, SchemaRequesting
{

	use MysqlFamilySupport;

	protected DbalAdapter $dbal;

	protected ForeignKeyColumnNameMismatchConfig $config;

	protected SchemaProvider $schema;

	public function __construct(SchemaProvider $schema, ?ForeignKeyColumnNameMismatchConfig $config = null)
	{
		$this->schema = $schema;
		$this->dbal = $schema->getDbal();
		$this->config = $config ?? new ForeignKeyColumnNameMismatchConfig();
	}

	public function getCategory(): AnalyserCategory
	{
		return AnalyserCategory::structure();
	}

}

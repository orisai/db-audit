<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Analyser;
use Orisai\DbAudit\AnalyserCategory;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Schema\ColumnCharsetClass;
use Orisai\DbAudit\Schema\SchemaProvider;
use Orisai\DbAudit\Schema\SchemaRequest;
use Orisai\DbAudit\Schema\SchemaRequesting;
use Orisai\DbAudit\Schema\TableExclude;

abstract class InvalidDateAuditor implements Analyser, SchemaRequesting
{

	use MysqlFamilySupport;

	protected DbalAdapter $dbal;

	protected SchemaProvider $schema;

	public function __construct(SchemaProvider $schema)
	{
		$this->schema = $schema;
		$this->dbal = $schema->getDbal();
	}

	/**
	 * Every date, datetime, or timestamp column of every base table is checked for row data, so table metadata
	 * is needed for the base-table list; no statistics and no exclude-driven scope expansion.
	 */
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

	public function getCategory(): AnalyserCategory
	{
		return AnalyserCategory::data();
	}

}

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

abstract class AutoIncrementNearLimitAuditor implements Analyser, SchemaRequesting
{

	use MysqlFamilySupport;

	protected DbalAdapter $dbal;

	protected SchemaProvider $schema;

	/** @var int<1, 99> */
	protected int $percentileThreshold = 90;

	public function __construct(SchemaProvider $schema)
	{
		$this->schema = $schema;
		$this->dbal = $schema->getDbal();
	}

	/**
	 * @param int<1, 99> $percentileThreshold
	 */
	public function setPercentileThreshold(int $percentileThreshold): void
	{
		$this->percentileThreshold = $percentileThreshold;
	}

	/**
	 * Every auto-increment column's usage is compared against its table's AUTO_INCREMENT counter, so table
	 * metadata is needed for the counters; no statistics and no exclude-driven scope expansion.
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

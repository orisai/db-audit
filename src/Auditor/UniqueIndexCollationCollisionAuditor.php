<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Analyser;
use Orisai\DbAudit\AnalyserCategory;
use Orisai\DbAudit\Collation\OutdatedCollationConfig;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Schema\SchemaProvider;
use Orisai\DbAudit\Schema\SchemaRequest;

abstract class UniqueIndexCollationCollisionAuditor implements Analyser
{

	use MysqlFamilySupport;

	protected DbalAdapter $dbal;

	protected OutdatedCollationConfig $config;

	protected SchemaProvider $schema;

	private bool $ownsSchema;

	public function __construct(
		DbalAdapter $dbal,
		?OutdatedCollationConfig $config = null,
		?SchemaProvider $schema = null
	)
	{
		$this->dbal = $dbal;
		$this->config = $config ?? new OutdatedCollationConfig();
		$this->ownsSchema = $schema === null;
		$this->schema = $schema ?? new SchemaProvider($dbal);
	}

	abstract public function getSchemaRequest(): SchemaRequest;

	/**
	 * A self-provisioned provider caches one snapshot, but the auditor is re-run against a schema mutated
	 * between calls, so an owned provider is rebuilt and re-primed (scoped to this auditor's declared
	 * requirement) per run. An injected provider is left untouched: a coordinator has already primed it.
	 */
	protected function primeOwnedSchema(): void
	{
		if (!$this->ownsSchema) {
			return;
		}

		$this->schema = new SchemaProvider($this->dbal);
		$request = $this->getSchemaRequest();
		$this->schema->primeTablesAndForeignKeys([$request]);
		$this->schema->primeColumns([$request]);
		$this->schema->primeStatistics([$request]);
	}

	public function getCategory(): AnalyserCategory
	{
		return AnalyserCategory::data();
	}

}

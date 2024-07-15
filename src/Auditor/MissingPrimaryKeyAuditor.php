<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Analyser;
use Orisai\DbAudit\AnalyserCategory;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Schema\SchemaProvider;
use Orisai\DbAudit\Schema\SchemaRequest;

abstract class MissingPrimaryKeyAuditor implements Analyser
{

	use MysqlFamilySupport;

	protected DbalAdapter $dbal;

	protected SchemaProvider $schema;

	private bool $ownsSchema;

	public function __construct(DbalAdapter $dbal, ?SchemaProvider $schema = null)
	{
		$this->dbal = $dbal;
		$this->ownsSchema = $schema === null;
		$this->schema = $schema ?? new SchemaProvider($dbal);
	}

	abstract public function getSchemaRequest(): SchemaRequest;

	/**
	 * An owned provider caches one whole-database snapshot, but the auditor is re-run against a schema mutated
	 * between calls, so it is rebuilt per run and left to its lazy whole-database fallback. An injected provider
	 * is left untouched: a coordinator has already primed it for the shared set of auditors.
	 */
	protected function refreshOwnedSchema(): void
	{
		if (!$this->ownsSchema) {
			return;
		}

		$this->schema = new SchemaProvider($this->dbal);
	}

	public function getCategory(): AnalyserCategory
	{
		return AnalyserCategory::structure();
	}

}

<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Schema;

final class SchemaCoordinator
{

	private SchemaProvider $provider;

	public function __construct(SchemaProvider $provider)
	{
		$this->provider = $provider;
	}

	/**
	 * Unions every auditor's declared requirements and performs the single scoped columns + statistics fetch
	 * for the whole set, so a shared provider reads the heavy INFORMATION_SCHEMA tables once.
	 *
	 * @param list<SchemaRequest> $requests
	 */
	public function prime(array $requests): void
	{
		// Table metadata and foreign keys are primed first so the columns/statistics table-scope expansion
		// reads the scoped foreign-key graph rather than triggering a whole-database FK fetch.
		$this->provider->primeTablesAndForeignKeys($requests);
		$this->provider->primeColumns($requests);
		$this->provider->primeStatistics($requests);
	}

}

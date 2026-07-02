<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Helper;

use Orisai\DbAudit\Analyser;
use Orisai\DbAudit\Report\AnalysisResult;
use Orisai\DbAudit\Schema\SchemaCoordinator;
use Orisai\DbAudit\Schema\SchemaProvider;
use Orisai\DbAudit\Schema\SchemaRequesting;

final class AuditorRunner
{

	/**
	 * Re-primes the shared provider for the auditor's declared requirement, then analyses — reproducing the
	 * per-run freshness the Runner gives, so a direct analyse after a schema change reads the new state.
	 */
	public static function analyse(SchemaProvider $schema, Analyser $auditor): AnalysisResult
	{
		if ($auditor instanceof SchemaRequesting) {
			(new SchemaCoordinator($schema))->prime([$auditor->getSchemaRequest()]);
		}

		return $auditor->analyse();
	}

}

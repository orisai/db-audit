<?php declare(strict_types = 1);

namespace Orisai\DbAudit;

use Orisai\DbAudit\Driver\SupportedDatabase;
use Orisai\DbAudit\Report\AnalysisResult;

/**
 * @todo - rozdělit auditory podle typu
 * 		- struktura
 * 		- data
 * 		- meta?
 * 		- různé reporty = různé baseline
 */
interface Analyser
{

	public function getCategory(): AnalyserCategory;

	/**
	 * @return list<SupportedDatabase>
	 */
	public function getSupportedDatabases(): array;

	public function analyse(): AnalysisResult;

}

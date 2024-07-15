<?php declare(strict_types = 1);

namespace Orisai\DbAudit;

use Orisai\DbAudit\Report\Violation;

/**
 * @todo - rozdělit auditory podle typu
 * 		- struktura
 * 		- data
 * 		- meta?
 * 		- různé reporty = různé baseline
 */
interface Analyser
{

	/**
	 * @return literal-string
	 */
	public static function getKey(): string;

	/**
	 * @return list<Violation>
	 */
	public function analyse(): array;

}

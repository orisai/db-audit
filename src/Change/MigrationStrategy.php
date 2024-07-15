<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Change;

/**
 * Renders a resolved plan into migration SQL. Different strategies (basic, zero-downtime, extra-column-copy) render the
 * same plan differently.
 */
interface MigrationStrategy
{

	/**
	 * @return literal-string
	 */
	public function render(ResolvedPlan $plan): string;

}

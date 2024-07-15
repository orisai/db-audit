<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Change;

/**
 * A declarative schema change tagged by an auditor on a fixable violation. The composer groups requests by
 * (database, table) into one ALTER and refuses conflicts — two requests with the same getAttribute() on one table
 * but different getComparisonKey() values.
 */
interface ChangeRequest
{

	public function getDatabase(): string;

	public function getTable(): string;

	/**
	 * Conflict bucket within a table: the same attribute with a different clause is a conflict, an identical clause
	 * is a duplicate.
	 */
	public function getAttribute(): string;

	/**
	 * @return string The canonical key the planner uses to dedup/conflict-detect within one (table, attribute) bucket.
	 */
	public function getComparisonKey(): string;

	/**
	 * Ordering of this clause within one table's ALTER. Lower runs first; the order must keep table options before
	 * index drops before column modifies before index adds, so a dropped index can be re-added in the same statement.
	 */
	public function getSortKey(): int;

	/**
	 * Whether this change counts as a fix in the generated-migration tally. Decoration clauses that are a
	 * side-effect of another fix (row-format bump, unique-index recreate, two-step prefix) return false so they
	 * do not inflate the count.
	 */
	public function countsAsFix(): bool;

}

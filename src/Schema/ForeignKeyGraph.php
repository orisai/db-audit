<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Schema;

final class ForeignKeyGraph
{

	/** @var list<ForeignKeyConstraint> */
	private array $constraints;

	/**
	 * Builds the graph from the flat per-column FK rows (one row per referencing column), grouping them into
	 * one constraint per referencing table + constraint name. Column order follows the row order, so the
	 * caller must pass the rows ordered by ORDINAL_POSITION (as SchemaProvider::getForeignKeys() does).
	 *
	 * @param list<array{
	 *     CONSTRAINT_NAME: string, TABLE_NAME: string, COLUMN_NAME: string, ORDINAL_POSITION: int,
	 *     REFERENCED_TABLE_NAME: string, REFERENCED_COLUMN_NAME: string,
	 *     UPDATE_RULE: string, DELETE_RULE: string
	 * }> $rows
	 */
	public function __construct(array $rows)
	{
		$grouped = [];
		$columns = [];
		$referencedColumns = [];
		foreach ($rows as $row) {
			$key = $row['TABLE_NAME'] . '.' . $row['CONSTRAINT_NAME'];
			if (!isset($grouped[$key])) {
				$grouped[$key] = [
					'name' => $row['CONSTRAINT_NAME'],
					'table' => $row['TABLE_NAME'],
					'referencedTable' => $row['REFERENCED_TABLE_NAME'],
					'updateRule' => $row['UPDATE_RULE'],
					'deleteRule' => $row['DELETE_RULE'],
				];
				$columns[$key] = [];
				$referencedColumns[$key] = [];
			}

			$columns[$key][] = $row['COLUMN_NAME'];
			$referencedColumns[$key][] = $row['REFERENCED_COLUMN_NAME'];
		}

		$constraints = [];
		foreach ($grouped as $key => $constraint) {
			$constraints[] = new ForeignKeyConstraint(
				$constraint['name'],
				$constraint['table'],
				$columns[$key],
				$constraint['referencedTable'],
				$referencedColumns[$key],
				$constraint['updateRule'],
				$constraint['deleteRule'],
			);
		}

		$this->constraints = $constraints;
	}

	/**
	 * @return list<ForeignKeyConstraint>
	 */
	public function getConstraints(): array
	{
		return $this->constraints;
	}

	/**
	 * The constraints whose referencing OR referenced table is in the given set, de-duplicated so a
	 * constraint with both ends in the set is returned once. A constraint with both ends outside the set is
	 * omitted entirely. This is the "in-scope given an included set" primitive: an FK stays in scope unless
	 * BOTH of its tables are excluded.
	 *
	 * @param array<string, bool> $tableNames
	 * @return list<ForeignKeyConstraint>
	 */
	public function getTouching(array $tableNames): array
	{
		$matched = [];
		foreach ($this->constraints as $constraint) {
			if (isset($tableNames[$constraint->table]) || isset($tableNames[$constraint->referencedTable])) {
				$matched[] = $constraint;
			}
		}

		return $matched;
	}

}

<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Unit\Schema;

use Orisai\DbAudit\Schema\ForeignKeyGraph;
use PHPUnit\Framework\TestCase;

final class ForeignKeyGraphTest extends TestCase
{

	/**
	 * @return array{
	 *     CONSTRAINT_NAME: string, TABLE_NAME: string, COLUMN_NAME: string, ORDINAL_POSITION: int,
	 *     REFERENCED_TABLE_NAME: string, REFERENCED_COLUMN_NAME: string,
	 *     UPDATE_RULE: string, DELETE_RULE: string
	 * }
	 */
	private function row(
		string $constraintName,
		string $table,
		string $column,
		int $ordinalPosition,
		string $referencedTable,
		string $referencedColumn,
		string $updateRule = 'RESTRICT',
		string $deleteRule = 'RESTRICT'
	): array
	{
		return [
			'CONSTRAINT_NAME' => $constraintName,
			'TABLE_NAME' => $table,
			'COLUMN_NAME' => $column,
			'ORDINAL_POSITION' => $ordinalPosition,
			'REFERENCED_TABLE_NAME' => $referencedTable,
			'REFERENCED_COLUMN_NAME' => $referencedColumn,
			'UPDATE_RULE' => $updateRule,
			'DELETE_RULE' => $deleteRule,
		];
	}

	public function testEmpty(): void
	{
		$graph = new ForeignKeyGraph([]);

		self::assertSame([], $graph->getTouching(['child' => true]));
		self::assertSame([], $graph->getConstraints());
	}

	public function testGetConstraintsReturnsAllGrouped(): void
	{
		$graph = new ForeignKeyGraph([
			$this->row('fk_a', 'child_a', 'col1', 1, 'parent_a', 'pcol1', 'CASCADE', 'SET NULL'),
			$this->row('fk_b_composite', 'child_b', 'col1', 1, 'parent_b', 'pcol1'),
			$this->row('fk_b_composite', 'child_b', 'col2', 2, 'parent_b', 'pcol2'),
		]);

		$constraints = $graph->getConstraints();
		self::assertCount(2, $constraints);

		self::assertSame('fk_a', $constraints[0]->name);
		self::assertSame('child_a', $constraints[0]->table);
		self::assertSame(['col1'], $constraints[0]->columns);
		self::assertSame('parent_a', $constraints[0]->referencedTable);
		self::assertSame(['pcol1'], $constraints[0]->referencedColumns);
		self::assertSame('CASCADE', $constraints[0]->updateRule);
		self::assertSame('SET NULL', $constraints[0]->deleteRule);

		self::assertSame('fk_b_composite', $constraints[1]->name);
		self::assertSame('child_b', $constraints[1]->table);
		self::assertSame(['col1', 'col2'], $constraints[1]->columns);
		self::assertSame(['pcol1', 'pcol2'], $constraints[1]->referencedColumns);
	}

	public function testSingleColumnGrouping(): void
	{
		$graph = new ForeignKeyGraph([
			$this->row('fk_city_country', 'city', 'country_code', 1, 'country', 'code', 'CASCADE', 'SET NULL'),
		]);

		$constraints = $graph->getTouching(['city' => true]);
		self::assertCount(1, $constraints);

		$constraint = $constraints[0];
		self::assertSame('fk_city_country', $constraint->name);
		self::assertSame('city', $constraint->table);
		self::assertSame(['country_code'], $constraint->columns);
		self::assertSame('country', $constraint->referencedTable);
		self::assertSame(['code'], $constraint->referencedColumns);
		self::assertSame('CASCADE', $constraint->updateRule);
		self::assertSame('SET NULL', $constraint->deleteRule);
	}

	public function testCompositeGroupingPreservesColumnOrder(): void
	{
		// Rows intentionally NOT in ordinal order; grouping preserves the row order it is given (the SQL
		// query already orders by ORDINAL_POSITION).
		$graph = new ForeignKeyGraph([
			$this->row('fk_district_region', 'district', 'a', 1, 'region', 'col_a'),
			$this->row('fk_district_region', 'district', 'b', 2, 'region', 'col_b'),
		]);

		$constraints = $graph->getTouching(['district' => true]);
		self::assertCount(1, $constraints);

		$constraint = $constraints[0];
		self::assertSame(['a', 'b'], $constraint->columns);
		self::assertSame(['col_a', 'col_b'], $constraint->referencedColumns);
	}

	public function testMultipleConstraints(): void
	{
		$graph = new ForeignKeyGraph([
			$this->row('fk_child_one_ref', 'child_one', 'ref_a', 1, 'shared_ref', 'code_a'),
			$this->row('fk_child_two_ref', 'child_two', 'ref_b', 1, 'shared_ref', 'code_b'),
		]);

		// Each referencing table touches exactly its own constraint.
		$touchingOne = $graph->getTouching(['child_one' => true]);
		self::assertCount(1, $touchingOne);
		self::assertSame('fk_child_one_ref', $touchingOne[0]->name);

		$touchingTwo = $graph->getTouching(['child_two' => true]);
		self::assertCount(1, $touchingTwo);
		self::assertSame('fk_child_two_ref', $touchingTwo[0]->name);

		// Both FKs reference the same table, so it touches both constraints.
		$touchingShared = $graph->getTouching(['shared_ref' => true]);
		self::assertCount(2, $touchingShared);
		self::assertSame('fk_child_one_ref', $touchingShared[0]->name);
		self::assertSame('fk_child_two_ref', $touchingShared[1]->name);
	}

	public function testGetTouchingDeduplicatesBothEndsInSet(): void
	{
		$graph = new ForeignKeyGraph([
			$this->row('fk_city_country', 'city', 'country_code', 1, 'country', 'code'),
		]);

		// Both referencing and referenced table in the set: the constraint must appear exactly once.
		$touching = $graph->getTouching(['city' => true, 'country' => true]);
		self::assertCount(1, $touching);
		self::assertSame('fk_city_country', $touching[0]->name);
	}

	public function testGetTouchingBoundaryAndBothOut(): void
	{
		$graph = new ForeignKeyGraph([
			$this->row('fk_city_country', 'city', 'country_code', 1, 'country', 'code'),
		]);

		// Only the referencing table included: boundary, still touching.
		$childOnly = $graph->getTouching(['city' => true]);
		self::assertCount(1, $childOnly);
		self::assertSame('fk_city_country', $childOnly[0]->name);

		// Only the referenced table included: boundary, still touching.
		$parentOnly = $graph->getTouching(['country' => true]);
		self::assertCount(1, $parentOnly);
		self::assertSame('fk_city_country', $parentOnly[0]->name);

		// Neither table included: not touching.
		self::assertSame([], $graph->getTouching(['unrelated' => true]));
	}

}

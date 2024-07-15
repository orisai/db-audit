<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Unit\Collation\Plan;

use Orisai\DbAudit\Collation\CollationTarget;
use Orisai\DbAudit\Collation\Plan\ColumnMigration;
use Orisai\DbAudit\Collation\Plan\MigrationPlan;
use Orisai\DbAudit\Collation\Plan\TableMigration;
use PHPUnit\Framework\TestCase;

final class MigrationPlanTest extends TestCase
{

	public function testEmpty(): void
	{
		$plan = new MigrationPlan(null, 'db', [], []);

		self::assertTrue($plan->isEmpty());
		self::assertNull($plan->databaseDefault);
		self::assertSame('db', $plan->databaseName);
		self::assertSame([], $plan->tables);
		self::assertSame([], $plan->unfixable);
	}

	public function testNonEmpty(): void
	{
		$table = new TableMigration('users', null, []);
		$plan = new MigrationPlan(null, 'db', [$table], []);

		self::assertFalse($plan->isEmpty());
	}

	public function testNonEmptyWithDatabaseDefault(): void
	{
		$target = new CollationTarget('utf8mb4', 'utf8mb4_unicode_ci');
		$plan = new MigrationPlan($target, 'db', [], []);

		self::assertFalse($plan->isEmpty());
	}

	public function testColumnMigration(): void
	{
		$target = new CollationTarget('utf8mb4', 'utf8mb4_unicode_ci');
		$column = new ColumnMigration('email', 'varchar(255)', $target, false, false);

		self::assertSame('email', $column->name);
		self::assertSame('varchar(255)', $column->columnType);
		self::assertSame($target, $column->target);
		self::assertFalse($column->underUniqueIndex);
		self::assertFalse($column->binaryTwoStep);
	}

	public function testColumnMigrationBinaryTwoStep(): void
	{
		$target = new CollationTarget('utf8mb4', 'utf8mb4_unicode_ci');
		$column = new ColumnMigration('legacy', 'varchar(100)', $target, false, true);

		self::assertTrue($column->binaryTwoStep);
	}

	public function testTableMigration(): void
	{
		$target = new CollationTarget('utf8mb4', 'utf8mb4_unicode_ci');
		$column = new ColumnMigration('email', 'varchar(255)', $target, true, false);
		$table = new TableMigration('users', $target, [$column]);

		self::assertSame('users', $table->table);
		self::assertSame($target, $table->tableDefault);
		self::assertSame([$column], $table->columns);
	}

}

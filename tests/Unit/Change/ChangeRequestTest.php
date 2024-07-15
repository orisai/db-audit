<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Unit\Change;

use Orisai\DbAudit\Change\ColumnTargetChange;
use Orisai\DbAudit\Change\DatabaseDefaultChange;
use Orisai\DbAudit\Change\DropIndexChange;
use Orisai\DbAudit\Change\IndexAddChange;
use Orisai\DbAudit\Change\RawClauseChange;
use Orisai\DbAudit\Change\TableDefaultCollationChange;
use Orisai\DbAudit\Change\TableEngineChange;
use PHPUnit\Framework\TestCase;

final class ChangeRequestTest extends TestCase
{

	public function testTableEngine(): void
	{
		$change = new TableEngineChange('db', 'orders', 'InnoDB');

		self::assertSame('db', $change->getDatabase());
		self::assertSame('orders', $change->getTable());
		self::assertSame('InnoDB', $change->getEngine());
		self::assertSame('engine', $change->getAttribute());
		self::assertSame('ENGINE=InnoDB', $change->getComparisonKey());
	}

	public function testDropIndex(): void
	{
		$change = new DropIndexChange('db', 'orders', 'idx_customer');

		self::assertSame('db', $change->getDatabase());
		self::assertSame('orders', $change->getTable());
		self::assertSame('idx_customer', $change->getIndexName());
		self::assertSame('drop_index:idx_customer', $change->getAttribute());
		self::assertSame('DROP INDEX `idx_customer`', $change->getComparisonKey());
		self::assertTrue($change->countsAsFix());
	}

	public function testColumnTarget(): void
	{
		$change = ColumnTargetChange::effective(
			'db',
			'orders',
			'note',
			'varchar(255)',
			'utf8mb4',
			'utf8mb4_general_ci',
			false,
			null,
			false,
			null,
			null,
			null,
			null,
		);

		self::assertSame('db', $change->getDatabase());
		self::assertSame('orders', $change->getTable());
		self::assertSame('note', $change->getColumn());
		self::assertSame('varchar(255)', $change->getType());
		self::assertSame('utf8mb4', $change->getCharset());
		self::assertSame('utf8mb4_general_ci', $change->getCollation());
		self::assertFalse($change->isNullable());
		self::assertNull($change->getDefault());
		self::assertFalse($change->hasOnUpdateCurrentTimestamp());
		self::assertNull($change->getComment());
		self::assertNull($change->getGenerated());
		self::assertSame('column:note', $change->getAttribute());
		self::assertSame(30, $change->getSortKey());
		self::assertTrue($change->countsAsFix());
		self::assertFalse($change->hasTwoStep());
		self::assertNull($change->getBinaryTwoStepType());
		self::assertStringStartsWith('MODIFY `note` varchar(255)', $change->getComparisonKey());
	}

	public function testColumnTargetSparseDeltaViaSetters(): void
	{
		$change = ColumnTargetChange::forColumn('db', 'orders', 'note');

		self::assertSame('db', $change->getDatabase());
		self::assertSame('orders', $change->getTable());
		self::assertSame('note', $change->getColumn());
		self::assertSame('column:note', $change->getAttribute());

		self::assertFalse($change->hasType());
		self::assertFalse($change->hasCharsetCollation());
		self::assertFalse($change->hasNullable());
		self::assertFalse($change->hasDefault());
		self::assertFalse($change->hasComment());
		self::assertFalse($change->hasOnUpdate());
		self::assertFalse($change->hasGenerated());
		self::assertFalse($change->hasCharLength());
		self::assertFalse($change->hasTwoStep());

		$returned = $change
			->setCharsetCollation('utf8mb4', 'utf8mb4_general_ci')
			->setNullable(false)
			->setComment('keep me');
		self::assertSame($change, $returned);

		self::assertTrue($change->hasCharsetCollation());
		self::assertSame('utf8mb4', $change->getCharset());
		self::assertSame('utf8mb4', $change->getTargetCharset());
		self::assertSame('utf8mb4_general_ci', $change->getCollation());

		self::assertTrue($change->hasNullable());
		self::assertFalse($change->isNullable());

		self::assertTrue($change->hasComment());
		self::assertSame('keep me', $change->getComment());

		// Untouched fields stay unset (tri-state: unset ≠ set to null).
		self::assertFalse($change->hasType());
		self::assertFalse($change->hasDefault());
		self::assertFalse($change->hasOnUpdate());
		self::assertFalse($change->hasGenerated());
		self::assertFalse($change->hasTwoStep());
	}

	public function testColumnTargetEffectiveMarksEveryFieldPresent(): void
	{
		$change = ColumnTargetChange::effective(
			'db',
			'orders',
			'note',
			'varchar(255)',
			'utf8mb4',
			'utf8mb4_general_ci',
			false,
			null,
			false,
			null,
			null,
			255,
			null,
		);

		self::assertTrue($change->hasType());
		self::assertTrue($change->hasCharsetCollation());
		self::assertTrue($change->hasNullable());
		self::assertTrue($change->hasDefault());
		self::assertTrue($change->hasComment());
		self::assertTrue($change->hasOnUpdate());
		self::assertTrue($change->hasGenerated());
		self::assertTrue($change->hasCharLength());
	}

	public function testColumnTargetSetDefaultAndGeneratedAndTwoStep(): void
	{
		$change = ColumnTargetChange::forColumn('db', 'orders', 'note')
			->setType('varchar(255)')
			->setDefault(['text' => 'x', 'isExpression' => false])
			->setGenerated(['expression' => 'a + b', 'stored' => true])
			->setOnUpdateCurrentTimestamp(true)
			->setBinaryTwoStep('varbinary(255)');

		self::assertTrue($change->hasType());
		self::assertSame('varchar(255)', $change->getType());

		self::assertTrue($change->hasDefault());
		self::assertSame(['text' => 'x', 'isExpression' => false], $change->getDefault());

		self::assertTrue($change->hasGenerated());
		self::assertSame(['expression' => 'a + b', 'stored' => true], $change->getGenerated());

		self::assertTrue($change->hasOnUpdate());
		self::assertTrue($change->hasOnUpdateCurrentTimestamp());

		self::assertTrue($change->hasTwoStep());
		self::assertSame('varbinary(255)', $change->getBinaryTwoStepType());
	}

	public function testColumnTargetComparisonKeyDiffersByField(): void
	{
		$base = ColumnTargetChange::effective(
			'db',
			'orders',
			'note',
			'varchar(255)',
			'utf8mb4',
			'utf8mb4_general_ci',
			false,
			null,
			false,
			null,
			null,
			null,
			null,
		);
		$nullable = ColumnTargetChange::effective(
			'db',
			'orders',
			'note',
			'varchar(255)',
			'utf8mb4',
			'utf8mb4_general_ci',
			true,
			null,
			false,
			null,
			null,
			null,
			null,
		);

		self::assertSame($base->getComparisonKey(), $base->getComparisonKey());
		self::assertNotSame($base->getComparisonKey(), $nullable->getComparisonKey());
	}

	public function testRawClause(): void
	{
		$change = new RawClauseChange('db', 'orders', 'row_format', 'ROW_FORMAT = DYNAMIC', 10, false);

		self::assertSame('db', $change->getDatabase());
		self::assertSame('orders', $change->getTable());
		self::assertSame('row_format', $change->getAttribute());
		self::assertSame('ROW_FORMAT = DYNAMIC', $change->getComparisonKey());
		self::assertSame(10, $change->getSortKey());
		self::assertFalse($change->countsAsFix());
	}

	public function testRawClauseCountsByDefault(): void
	{
		$change = new RawClauseChange('db', 'orders', 'table_default', 'DEFAULT CHARACTER SET = utf8mb4', 11);

		self::assertTrue($change->countsAsFix());
	}

	public function testColumnTargetTwoStep(): void
	{
		$change = ColumnTargetChange::effective(
			'db',
			'orders',
			'note',
			'varchar(255)',
			'utf8mb4',
			'utf8mb4_general_ci',
			true,
			null,
			false,
			null,
			null,
			255,
			'varbinary(255)',
		);

		self::assertTrue($change->hasTwoStep());
		self::assertSame('varbinary(255)', $change->getBinaryTwoStepType());
		self::assertSame(255, $change->getEffectiveCharLength());
		self::assertSame('utf8mb4', $change->getTargetCharset());
		self::assertSame('column:note', $change->getAttribute());
		self::assertTrue($change->countsAsFix());
	}

	public function testDatabaseDefault(): void
	{
		$change = new DatabaseDefaultChange('shop', 'utf8mb4', 'utf8mb4_general_ci');

		self::assertSame('shop', $change->getDatabase());
		self::assertSame('utf8mb4', $change->getCharset());
		self::assertSame('utf8mb4_general_ci', $change->getCollation());
		self::assertSame('database_default', $change->getAttribute());
		self::assertSame('', $change->getTable());
		self::assertTrue($change->countsAsFix());
	}

	public function testDropIndexNotCountingAsFix(): void
	{
		$change = new DropIndexChange('db', 'orders', 'uniq_email', false);

		self::assertFalse($change->countsAsFix());
	}

	public function testColumnTargetStructuredSize(): void
	{
		$change = ColumnTargetChange::effective(
			'db',
			'orders',
			'note',
			'varchar(255)',
			'utf8mb4',
			'utf8mb4_general_ci',
			true,
			null,
			false,
			null,
			null,
			255,
			null,
		);

		self::assertSame(255, $change->getEffectiveCharLength());
		self::assertSame('utf8mb4', $change->getTargetCharset());
		// Unchanged behaviour:
		self::assertSame('column:note', $change->getAttribute());
		self::assertTrue($change->countsAsFix());
	}

	public function testColumnTargetSizeDefaultsNull(): void
	{
		$change = ColumnTargetChange::effective(
			'db',
			'orders',
			'note',
			'int',
			null,
			null,
			false,
			null,
			false,
			null,
			null,
			null,
			null,
		);

		self::assertNull($change->getEffectiveCharLength());
		self::assertNull($change->getTargetCharset());
		self::assertNull($change->getCharset());
		self::assertNull($change->getCollation());
	}

	public function testTableDefaultCollation(): void
	{
		$change = new TableDefaultCollationChange('db', 'orders', 'utf8mb4', 'utf8mb4_general_ci');

		self::assertSame('db', $change->getDatabase());
		self::assertSame('orders', $change->getTable());
		self::assertSame('utf8mb4', $change->getCharset());
		self::assertSame('utf8mb4_general_ci', $change->getCollation());
		self::assertSame('table_default', $change->getAttribute());
		self::assertSame('DEFAULT CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci', $change->getComparisonKey());
		self::assertSame(11, $change->getSortKey());
		self::assertTrue($change->countsAsFix());
	}

	public function testIndexAdd(): void
	{
		$change = new IndexAddChange(
			'db',
			'orders',
			'uq_name_scope',
			true,
			[
				['column' => 'name', 'subPart' => null],
				['column' => 'scope', 'subPart' => 20],
			],
		);

		self::assertSame('db', $change->getDatabase());
		self::assertSame('orders', $change->getTable());
		self::assertSame('uq_name_scope', $change->getIndexName());
		self::assertTrue($change->isUnique());
		self::assertSame(
			[['column' => 'name', 'subPart' => null], ['column' => 'scope', 'subPart' => 20]],
			$change->getMembers(),
		);
		self::assertSame('add_index:uq_name_scope', $change->getAttribute());
		self::assertSame('ADD UNIQUE INDEX `uq_name_scope` (`name`, `scope`(20))', $change->getComparisonKey());
		self::assertSame(40, $change->getSortKey());
		self::assertFalse($change->countsAsFix());
	}

	public function testIndexAddNonUnique(): void
	{
		$change = new IndexAddChange(
			'db',
			'orders',
			'idx_status',
			false,
			[['column' => 'status', 'subPart' => null]],
		);

		self::assertFalse($change->isUnique());
		self::assertSame('ADD INDEX `idx_status` (`status`)', $change->getComparisonKey());
	}

}

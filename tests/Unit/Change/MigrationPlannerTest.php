<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Unit\Change;

use Orisai\DbAudit\Change\BasicMigrationStrategy;
use Orisai\DbAudit\Change\ChangeRequest;
use Orisai\DbAudit\Change\ColumnTargetChange;
use Orisai\DbAudit\Change\DatabaseDefaultChange;
use Orisai\DbAudit\Change\DropIndexChange;
use Orisai\DbAudit\Change\MigrationPlanner;
use Orisai\DbAudit\Change\RawClauseChange;
use Orisai\DbAudit\Change\ResolvedPlan;
use Orisai\DbAudit\Change\SchemaContext;
use Orisai\DbAudit\Change\TableEngineChange;
use Orisai\DbAudit\Change\TableSchema;
use Orisai\DbAudit\Report\TableViolationSource;
use Orisai\DbAudit\Report\Violation;
use Orisai\DbAudit\Schema\ForeignKeyGraph;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\FakeDbalAdapter;

final class MigrationPlannerTest extends TestCase
{

	public function testMergesChangesForOneTableIntoOneAlter(): void
	{
		$plan = $this->plan([
			$this->fixable(new TableEngineChange('db', 'orders', 'InnoDB')),
			$this->fixable(new DropIndexChange('db', 'orders', 'idx_a')),
		]);

		// Ordered by change type: table options (ENGINE) before index drops.
		self::assertSame("ALTER TABLE `orders` ENGINE=InnoDB, DROP INDEX `idx_a`;\n", $this->render($plan));
		self::assertSame(2, $plan->getGeneratedCount());
		self::assertSame([], $plan->getConflicts());
	}

	public function testRebuildsForeignKeysAroundColumnChange(): void
	{
		$graph = new ForeignKeyGraph([
			[
				'CONSTRAINT_NAME' => 'fk_child_parent',
				'TABLE_NAME' => 'child',
				'COLUMN_NAME' => 'p_id',
				'ORDINAL_POSITION' => 1,
				'REFERENCED_TABLE_NAME' => 'parent',
				'REFERENCED_COLUMN_NAME' => 'id',
				'UPDATE_RULE' => 'NO ACTION',
				'DELETE_RULE' => 'NO ACTION',
			],
		]);

		$plan = (new MigrationPlanner())->plan(
			[$this->fixable(
				ColumnTargetChange::effective(
					'db',
					'child',
					'p_id',
					'varchar(20)',
					'utf8mb4',
					'utf8mb4_general_ci',
					false,
					null,
					false,
					null,
					null,
					null,
					null,
				),
			)],
			$graph,
		);

		self::assertCount(1, $plan->getForeignKeys());
		self::assertTrue($plan->getSessionWrap());

		self::assertSame(
			"SET @ORISAI_DBAUDIT_FK = @@SESSION.foreign_key_checks;\n"
			. "SET SESSION foreign_key_checks = 0;\n"
			. "ALTER TABLE `child` DROP FOREIGN KEY `fk_child_parent`;\n"
			. "ALTER TABLE `child` MODIFY `p_id` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL;\n"
			. 'ALTER TABLE `child` ADD CONSTRAINT `fk_child_parent` FOREIGN KEY (`p_id`)'
			. " REFERENCES `parent` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION;\n"
			. "SET SESSION foreign_key_checks = @ORISAI_DBAUDIT_FK;\n",
			$this->render($plan),
		);
	}

	public function testSeparateTablesGetSeparateAlters(): void
	{
		$plan = $this->plan([
			$this->fixable(new TableEngineChange('db', 'b_table', 'InnoDB')),
			$this->fixable(new TableEngineChange('db', 'a_table', 'InnoDB')),
		]);

		self::assertSame(
			"ALTER TABLE `a_table` ENGINE=InnoDB;\nALTER TABLE `b_table` ENGINE=InnoDB;\n",
			$this->render($plan),
		);
		self::assertSame(2, $plan->getGeneratedCount());
	}

	public function testDuplicateChangeIsDeduplicated(): void
	{
		$plan = $this->plan([
			$this->fixable(new TableEngineChange('db', 'orders', 'InnoDB')),
			$this->fixable(new TableEngineChange('db', 'orders', 'InnoDB')),
		]);

		self::assertSame("ALTER TABLE `orders` ENGINE=InnoDB;\n", $this->render($plan));
		self::assertSame(1, $plan->getGeneratedCount());
		self::assertSame([], $plan->getConflicts());
	}

	public function testConflictIsRefusedButOthersSurvive(): void
	{
		$plan = $this->plan([
			$this->fixable(new TableEngineChange('db', 'orders', 'InnoDB')),
			$this->fixable(new TableEngineChange('db', 'orders', 'MyISAM')),
			$this->fixable(new DropIndexChange('db', 'orders', 'idx_a')),
		]);

		// The engine conflict is dropped; the index drop still happens.
		self::assertSame("ALTER TABLE `orders` DROP INDEX `idx_a`;\n", $this->render($plan));
		self::assertSame(1, $plan->getGeneratedCount());
		self::assertCount(1, $plan->getConflicts());
		self::assertSame('change.conflict', $plan->getConflicts()[0]->getKey());
		self::assertStringContainsString('orders', $plan->getConflicts()[0]->getMessage());
	}

	public function testMergesSparseColumnDeltasOntoCurrentDefinition(): void
	{
		$schema = new SchemaContext([
			'orders' => new TableSchema(
				['note' => [
					'type' => 'varchar(100)',
					'charset' => 'utf8mb3',
					'collation' => 'utf8mb3_general_ci',
					'nullable' => true,
					'default' => null,
					'onUpdateCurrentTimestamp' => false,
					'comment' => 'keep me',
					'generated' => null,
					'charLength' => 100,
					'dataType' => 'varchar',
				]],
				[],
				'DYNAMIC',
			),
		], ['utf8mb3' => 3, 'utf8mb4' => 4]);

		$plan = (new MigrationPlanner())->plan(
			[
				$this->fixable(
					ColumnTargetChange::forColumn('db', 'orders', 'note')
						->setCharsetCollation('utf8mb4', 'utf8mb4_general_ci'),
				),
				$this->fixable(
					ColumnTargetChange::forColumn('db', 'orders', 'note')->setNullable(false),
				),
			],
			null,
			$schema,
		);

		// The charset delta and the NOT NULL delta merge into one MODIFY; the current comment, untouched by either
		// delta, is preserved.
		self::assertSame(
			'ALTER TABLE `orders` MODIFY `note` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'
			. " NOT NULL COMMENT 'keep me';\n",
			(new BasicMigrationStrategy(new FakeDbalAdapter()))->render($plan),
		);
		self::assertSame(1, $plan->getGeneratedCount());
		self::assertSame([], $plan->getConflicts());
	}

	public function testMergeConflictsWhenSameFieldSetDifferently(): void
	{
		$schema = new SchemaContext([
			'orders' => new TableSchema(
				['note' => $this->col(100, 'varchar', 'utf8mb3', 'utf8mb3_general_ci')],
				[],
				'DYNAMIC',
			),
		], ['utf8mb3' => 3, 'utf8mb4' => 4, 'latin1' => 1]);

		$plan = (new MigrationPlanner())->plan(
			[
				$this->fixable(
					ColumnTargetChange::forColumn('db', 'orders', 'note')
						->setCharsetCollation('utf8mb4', 'utf8mb4_general_ci'),
				),
				$this->fixable(
					ColumnTargetChange::forColumn('db', 'orders', 'note')
						->setCharsetCollation('latin1', 'latin1_swedish_ci'),
				),
			],
			null,
			$schema,
		);

		self::assertSame('', (new BasicMigrationStrategy(new FakeDbalAdapter()))->render($plan));
		self::assertSame(0, $plan->getGeneratedCount());
		$conflicts = $plan->getConflicts();
		self::assertCount(1, $conflicts);
		self::assertSame('change.conflict', $conflicts[0]->getKey());
		self::assertStringContainsString('note', $conflicts[0]->getMessage());
	}

	public function testSingleFullColumnChangeMergesToItself(): void
	{
		$schema = new SchemaContext([
			'orders' => new TableSchema(
				['note' => $this->col(10, 'varchar', 'utf8mb3', 'utf8mb3_general_ci')],
				[],
				'DYNAMIC',
			),
		], ['utf8mb3' => 3, 'utf8mb4' => 4]);

		$change = ColumnTargetChange::effective(
			'db',
			'orders',
			'note',
			'varchar(10)',
			'utf8mb4',
			'utf8mb4_general_ci',
			false,
			null,
			false,
			null,
			null,
			10,
			null,
		);

		$merged = (new MigrationPlanner())->plan([$this->fixable($change)], null, $schema);
		$direct = (new MigrationPlanner())->plan([$this->fixable($change)]);

		$strategy = new BasicMigrationStrategy(new FakeDbalAdapter());
		self::assertSame(
			"ALTER TABLE `orders` MODIFY `note` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL;\n",
			$strategy->render($merged),
		);
		// Merging a single full change onto the current definition reproduces the schema-less passthrough exactly.
		self::assertSame($strategy->render($direct), $strategy->render($merged));
		self::assertSame(1, $merged->getGeneratedCount());
	}

	public function testViolationsWithoutChangeAreIgnored(): void
	{
		$plan = $this->plan([
			new Violation('empty_table', 'msg', new TableViolationSource('db', null, 'orders')),
		]);

		self::assertSame('', $this->render($plan));
		self::assertSame(0, $plan->getGeneratedCount());
	}

	public function testRendersDatabaseDefaultThenTableChanges(): void
	{
		$plan = $this->plan([
			$this->fixable(new DatabaseDefaultChange('shop', 'utf8mb4', 'utf8mb4_general_ci')),
			$this->fixable(
				ColumnTargetChange::effective(
					'shop',
					'orders',
					'note',
					'varchar(10)',
					'utf8mb4',
					'utf8mb4_general_ci',
					false,
					null,
					false,
					null,
					null,
					null,
					null,
				),
			),
		]);

		self::assertSame(
			"ALTER DATABASE `shop` CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;\n"
			. "ALTER TABLE `orders` MODIFY `note` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL;\n",
			$this->render($plan),
		);
		self::assertSame(2, $plan->getGeneratedCount());
	}

	public function testDatabaseDefaultConflictIsReported(): void
	{
		$plan = $this->plan([
			$this->fixable(new DatabaseDefaultChange('shop', 'utf8mb4', 'utf8mb4_general_ci')),
			$this->fixable(new DatabaseDefaultChange('shop', 'latin1', 'latin1_swedish_ci')),
		]);

		self::assertSame('', $this->render($plan));
		self::assertSame(0, $plan->getGeneratedCount());
		$conflicts = $plan->getConflicts();
		self::assertCount(1, $conflicts);
		self::assertSame('change.conflict', $conflicts[0]->getKey());
		self::assertStringContainsString('shop', $conflicts[0]->getMessage());
		self::assertNull($plan->getDatabaseDefault());
	}

	public function testDuplicateDatabaseDefaultIsAppliedOnce(): void
	{
		$plan = $this->plan([
			$this->fixable(new DatabaseDefaultChange('shop', 'utf8mb4', 'utf8mb4_general_ci')),
			$this->fixable(new DatabaseDefaultChange('shop', 'utf8mb4', 'utf8mb4_general_ci')),
		]);

		self::assertSame(
			"ALTER DATABASE `shop` CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;\n",
			$this->render($plan),
		);
		self::assertSame(1, $plan->getGeneratedCount());
		self::assertSame([], $plan->getConflicts());
		self::assertNotNull($plan->getDatabaseDefault());
	}

	public function testRendersPrefixAlterBeforeCombinedAlter(): void
	{
		$plan = $this->plan([
			$this->fixable(
				ColumnTargetChange::effective(
					'shop',
					'orders',
					'note',
					'varchar(10)',
					'utf8mb4',
					'utf8mb4_general_ci',
					false,
					null,
					false,
					null,
					null,
					10,
					'varbinary(10)',
				),
			),
		]);

		self::assertSame(
			"ALTER TABLE `orders` MODIFY `note` varbinary(10) NOT NULL;\n"
			. "ALTER TABLE `orders` MODIFY `note` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL;\n",
			$this->render($plan),
		);
		// The two-step column renders a prefix and a combined MODIFY from one object but counts once.
		self::assertSame(1, $plan->getGeneratedCount());
	}

	public function testDecorationClausesAreOrderedButNotCounted(): void
	{
		$plan = $this->plan([
			$this->fixable(
				new RawClauseChange('shop', 'orders', 'add_index:uniq', 'ADD UNIQUE INDEX `uniq` (`note`)', 40, false),
			),
			$this->fixable(
				ColumnTargetChange::effective(
					'shop',
					'orders',
					'note',
					'varchar(10)',
					'utf8mb4',
					'utf8mb4_general_ci',
					false,
					null,
					false,
					null,
					null,
					null,
					null,
				),
			),
			$this->fixable(new RawClauseChange('shop', 'orders', 'row_format', 'ROW_FORMAT = DYNAMIC', 10, false)),
			$this->fixable(new DropIndexChange('shop', 'orders', 'uniq', false)),
		]);

		self::assertSame(
			'ALTER TABLE `orders` ROW_FORMAT = DYNAMIC, DROP INDEX `uniq`,'
			. ' MODIFY `note` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,'
			. " ADD UNIQUE INDEX `uniq` (`note`);\n",
			$this->render($plan),
		);
		self::assertSame(1, $plan->getGeneratedCount());
	}

	public function testInjectsDynamicRowFormatWhenConvertedColumnExceeds767(): void
	{
		$schema = new SchemaContext([
			'city' => new TableSchema(
				['name' => $this->col(300, 'varchar', 'utf8mb3', 'utf8mb3_general_ci')],
				[['name' => 'uq', 'unique' => true, 'members' => [['column' => 'name', 'subPart' => null]]]],
				'COMPACT',
			),
		], ['utf8mb3' => 3, 'utf8mb4' => 4]);

		$plan = (new MigrationPlanner())->plan(
			[$this->fixable(
				ColumnTargetChange::effective(
					'db',
					'city',
					'name',
					'varchar(300)',
					'utf8mb4',
					'utf8mb4_general_ci',
					false,
					null,
					false,
					null,
					null,
					300,
					null,
				),
			)],
			null,
			$schema,
		);

		// 300 * 4 = 1200 > 767 under COMPACT -> DYNAMIC injected, ordered before the MODIFY.
		self::assertSame(
			"ALTER TABLE `city` ROW_FORMAT = DYNAMIC, MODIFY `name` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL;\n",
			(new BasicMigrationStrategy(new FakeDbalAdapter()))->render($plan),
		);
		self::assertSame(1, $plan->getGeneratedCount()); // ROW_FORMAT is countsAsFix=false
	}

	public function testSubPartAwarePerColumnCheckDoesNotInjectDynamicWhenPrefixUnder767(): void
	{
		$schema = new SchemaContext([
			'city' => new TableSchema(
				['name' => $this->col(300, 'varchar', 'utf8mb3', 'utf8mb3_general_ci')],
				[['name' => 'uq', 'unique' => true, 'members' => [['column' => 'name', 'subPart' => 50]]]],
				'COMPACT',
			),
		], ['utf8mb3' => 3, 'utf8mb4' => 4]);

		$plan = (new MigrationPlanner())->plan(
			[$this->fixable(
				ColumnTargetChange::effective(
					'db',
					'city',
					'name',
					'varchar(300)',
					'utf8mb4',
					'utf8mb4_general_ci',
					false,
					null,
					false,
					null,
					null,
					300,
					null,
				),
			)],
			null,
			$schema,
		);

		// subPart=50, 50*4=200 < 767 -> no ROW_FORMAT = DYNAMIC injected.
		self::assertSame(
			"ALTER TABLE `city` MODIFY `name` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL;\n",
			(new BasicMigrationStrategy(new FakeDbalAdapter()))->render($plan),
		);
		self::assertSame(1, $plan->getGeneratedCount());
		self::assertSame([], $plan->getRefusals());
	}

	public function testRefusesColumnWhenIndexExceeds3072(): void
	{
		$schema = new SchemaContext([
			'lookup' => new TableSchema(
				['big' => $this->col(1_000, 'varchar', 'utf8mb3', 'utf8mb3_czech_ci')],
				[['name' => 'uq_big', 'unique' => true, 'members' => [['column' => 'big', 'subPart' => null]]]],
				'DYNAMIC',
			),
		], ['utf8mb3' => 3, 'utf8mb4' => 4]);

		// 1000 * 4 = 4000 > 3072 under utf8mb4 — beyond the hard key limit no row format can satisfy.
		$plan = (new MigrationPlanner())->plan(
			[$this->fixable(
				ColumnTargetChange::effective(
					'db',
					'lookup',
					'big',
					'varchar(1000)',
					'utf8mb4',
					'utf8mb4_general_ci',
					false,
					null,
					false,
					null,
					null,
					1_000,
					null,
				),
			)],
			null,
			$schema,
		);

		self::assertSame('', (new BasicMigrationStrategy(new FakeDbalAdapter()))->render($plan));
		self::assertSame(0, $plan->getGeneratedCount());
		$refusals = $plan->getRefusals();
		self::assertCount(1, $refusals);
		self::assertSame('change.index_too_long', $refusals[0]->getKey());
		self::assertStringContainsString('[big]', $refusals[0]->getMessage());
		self::assertStringContainsString('3072-byte key length limit', $refusals[0]->getMessage());
	}

	public function testRefusesOnlyConvertingMemberOfOverlongIndex(): void
	{
		$schema = new SchemaContext([
			'lookup' => new TableSchema(
				[
					'big' => $this->col(1_000, 'varchar', 'utf8mb3', 'utf8mb3_czech_ci'),
					'small' => $this->col(10, 'varchar', 'utf8mb4', 'utf8mb4_general_ci'),
				],
				[['name' => 'idx', 'unique' => false, 'members' => [
					['column' => 'big', 'subPart' => null],
					['column' => 'small', 'subPart' => null],
				]]],
				'DYNAMIC',
			),
		], ['utf8mb3' => 3, 'utf8mb4' => 4]);

		// `big` converts utf8mb3 → utf8mb4 (1000 * 4 = 4000 bytes > 3072 hard limit).
		// `small` only flips NULL, keeping its current utf8mb4 — a real change that is not a conversion.
		$plan = (new MigrationPlanner())->plan(
			[
				$this->fixable(ColumnTargetChange::effective(
					'db',
					'lookup',
					'big',
					'varchar(1000)',
					'utf8mb4',
					'utf8mb4_general_ci',
					false,
					null,
					false,
					null,
					null,
					1_000,
					null,
				)),
				$this->fixable(
					ColumnTargetChange::forColumn('db', 'lookup', 'small')->setNullable(true),
				),
			],
			null,
			$schema,
		);

		$refusals = $plan->getRefusals();
		self::assertCount(1, $refusals);
		self::assertSame('change.index_too_long', $refusals[0]->getKey());
		self::assertStringContainsString('[big]', $refusals[0]->getMessage());
		self::assertStringNotContainsString('[small]', $refusals[0]->getMessage());

		$sql = (new BasicMigrationStrategy(new FakeDbalAdapter()))->render($plan);
		self::assertStringContainsString('MODIFY `small`', $sql);
		self::assertStringNotContainsString('MODIFY `big`', $sql);
		self::assertSame(1, $plan->getGeneratedCount());
	}

	public function testRefusesPureLengthWidenWhenCompositeIndexExceeds3072(): void
	{
		$schema = new SchemaContext([
			'link' => new TableSchema(
				[
					'a' => $this->col(700, 'varchar', 'utf8mb4', 'utf8mb4_general_ci'),
					'b' => $this->col(50, 'varchar', 'utf8mb4', 'utf8mb4_general_ci'),
				],
				[['name' => 'idx_ab', 'unique' => false, 'members' => [
					['column' => 'a', 'subPart' => null],
					['column' => 'b', 'subPart' => null],
				]]],
				'DYNAMIC',
			),
		], ['utf8mb4' => 4]);

		// A pure-length widen (same charset utf8mb4) of `a` from 700 to 800: (800 + 50) * 4 = 3400 > 3072, so the
		// widen is held back even though no charset/collation converts.
		$plan = (new MigrationPlanner())->plan(
			[$this->fixable(ColumnTargetChange::effective(
				'db',
				'link',
				'a',
				'varchar(800)',
				'utf8mb4',
				'utf8mb4_general_ci',
				false,
				null,
				false,
				null,
				null,
				800,
				null,
			))],
			null,
			$schema,
		);

		self::assertSame('', (new BasicMigrationStrategy(new FakeDbalAdapter()))->render($plan));
		self::assertSame(0, $plan->getGeneratedCount());
		$refusals = $plan->getRefusals();
		self::assertCount(1, $refusals);
		self::assertSame('change.index_too_long', $refusals[0]->getKey());
		self::assertStringContainsString('[a]', $refusals[0]->getMessage());
		self::assertStringContainsString('3072-byte key length limit', $refusals[0]->getMessage());
	}

	public function testForeignKeyHeldBackWhenEndpointsDisagree(): void
	{
		$graph = $this->foreignKeyGraph('fk_child_parent', 'child', 'p_code', 'parent', 'code');
		$schema = new SchemaContext([
			'child' => $this->utf8mb3Table('p_code'),
			'parent' => $this->utf8mb3Table('code'),
		], ['utf8mb3' => 3, 'utf8mb4' => 4]);

		// Only the child endpoint converts; the parent is left untouched, so the FK is held back.
		$plan = (new MigrationPlanner())->plan(
			[$this->fixable($this->utf8mb4Change('child', 'p_code'))],
			$graph,
			$schema,
		);

		self::assertSame('', (new BasicMigrationStrategy(new FakeDbalAdapter()))->render($plan));
		self::assertSame([], $plan->getForeignKeys());
		self::assertSame(0, $plan->getGeneratedCount());
		$refusals = $plan->getRefusals();
		self::assertCount(1, $refusals);
		self::assertSame('change.foreign_key_inconsistent', $refusals[0]->getKey());
		self::assertStringContainsString('fk_child_parent', $refusals[0]->getMessage());
	}

	public function testForeignKeyKeptWhenAllEndpointsConverge(): void
	{
		$graph = $this->foreignKeyGraph('fk_child_parent', 'child', 'p_code', 'parent', 'code');
		$schema = new SchemaContext([
			'child' => $this->utf8mb3Table('p_code'),
			'parent' => $this->utf8mb3Table('code'),
		], ['utf8mb3' => 3, 'utf8mb4' => 4]);

		// Both endpoints converge to the same target, so the FK is rebuilt around the converted columns.
		$plan = (new MigrationPlanner())->plan(
			[
				$this->fixable($this->utf8mb4Change('child', 'p_code')),
				$this->fixable($this->utf8mb4Change('parent', 'code')),
			],
			$graph,
			$schema,
		);

		self::assertSame([], $plan->getRefusals());
		self::assertCount(1, $plan->getForeignKeys());
		self::assertTrue($plan->getSessionWrap());
		self::assertSame(2, $plan->getGeneratedCount());
		$sql = (new BasicMigrationStrategy(new FakeDbalAdapter()))->render($plan);
		self::assertStringContainsString('MODIFY `p_code`', $sql);
		self::assertStringContainsString('MODIFY `code`', $sql);
		self::assertStringContainsString('DROP FOREIGN KEY `fk_child_parent`', $sql);
	}

	public function testForeignKeyCycleHoldsBackToFixpoint(): void
	{
		// A cyclic FK graph (t1 -> t2 -> t1): fk1 t1.ref -> t2.shared, fk2 t2.shared -> t1.id. t1.ref and
		// t2.shared convert; t1.id does not. fk2 is mixed and drops t2.shared; that reverts the parent end of
		// fk1, which only THEN becomes mixed and must also be held back. Without the fixpoint loop t1.ref would
		// convert against an unconverted t2.shared — a charset-mismatched rebuild.
		$graph = new ForeignKeyGraph([
			[
				'CONSTRAINT_NAME' => 'fk1',
				'TABLE_NAME' => 't1',
				'COLUMN_NAME' => 'ref',
				'ORDINAL_POSITION' => 1,
				'REFERENCED_TABLE_NAME' => 't2',
				'REFERENCED_COLUMN_NAME' => 'shared',
				'UPDATE_RULE' => 'NO ACTION',
				'DELETE_RULE' => 'NO ACTION',
			],
			[
				'CONSTRAINT_NAME' => 'fk2',
				'TABLE_NAME' => 't2',
				'COLUMN_NAME' => 'shared',
				'ORDINAL_POSITION' => 1,
				'REFERENCED_TABLE_NAME' => 't1',
				'REFERENCED_COLUMN_NAME' => 'id',
				'UPDATE_RULE' => 'NO ACTION',
				'DELETE_RULE' => 'NO ACTION',
			],
		]);
		$schema = new SchemaContext([
			't1' => new TableSchema(
				[
					'id' => $this->col(20, 'varchar', 'utf8mb3', 'utf8mb3_czech_ci'),
					'ref' => $this->col(20, 'varchar', 'utf8mb3', 'utf8mb3_czech_ci'),
				],
				[],
				'DYNAMIC',
			),
			't2' => $this->utf8mb3Table('shared'),
		], ['utf8mb3' => 3, 'utf8mb4' => 4]);

		$plan = (new MigrationPlanner())->plan(
			[
				$this->fixable($this->utf8mb4Change('t1', 'ref')),
				$this->fixable($this->utf8mb4Change('t2', 'shared')),
			],
			$graph,
			$schema,
		);

		self::assertSame('', (new BasicMigrationStrategy(new FakeDbalAdapter()))->render($plan));
		self::assertSame([], $plan->getForeignKeys());
		self::assertSame(0, $plan->getGeneratedCount());
		self::assertCount(2, $plan->getRefusals());
		foreach ($plan->getRefusals() as $refusal) {
			self::assertSame('change.foreign_key_inconsistent', $refusal->getKey());
		}
	}

	public function testForeignKeyAlignChildAdoptsParentCharset(): void
	{
		$graph = $this->foreignKeyGraph('fk_child_parent', 'child', 'c', 'parent', 'p');

		$child = ColumnTargetChange::effective(
			'db',
			'child',
			'c',
			'varchar(20)',
			'utf8mb3',
			'utf8mb3_general_ci',
			false,
			null,
			false,
			null,
			null,
			20,
			null,
		);
		$parent = ColumnTargetChange::effective(
			'db',
			'parent',
			'p',
			'varchar(20)',
			'utf8mb4',
			'utf8mb4_general_ci',
			false,
			null,
			false,
			null,
			null,
			20,
			null,
		);

		// Alignment mutates the surviving child change in place, so the passed-in object reflects the result.
		// utf8mb3 (MAXLEN 3) -> utf8mb4 (MAXLEN 4) is a non-narrowing widen, so the child adopts the parent charset.
		$schema = new SchemaContext([], ['utf8mb3' => 3, 'utf8mb4' => 4]);
		(new MigrationPlanner())->plan([$this->fixable($child), $this->fixable($parent)], $graph, $schema);

		self::assertSame('utf8mb4', $child->getCharset());
		self::assertSame('utf8mb4_general_ci', $child->getCollation());
		self::assertSame('utf8mb4', $parent->getCharset());
		self::assertSame('utf8mb4_general_ci', $parent->getCollation());
	}

	public function testForeignKeyAlignKeepsWiderChildWhenParentCharsetNarrower(): void
	{
		$graph = $this->foreignKeyGraph('fk_child_parent', 'child', 'c', 'parent', 'p');

		$child = ColumnTargetChange::effective(
			'db',
			'child',
			'c',
			'varchar(20)',
			'utf8mb4',
			'utf8mb4_general_ci',
			false,
			null,
			false,
			null,
			null,
			20,
			null,
		);
		$parent = ColumnTargetChange::effective(
			'db',
			'parent',
			'p',
			'varchar(20)',
			'latin1',
			'latin1_swedish_ci',
			false,
			null,
			false,
			null,
			null,
			20,
			null,
		);

		// latin1 (MAXLEN 1) is narrower than utf8mb4 (MAXLEN 4): driving the child down would truncate its
		// multibyte data, so alignment leaves the child charset untouched.
		$schema = new SchemaContext([], ['latin1' => 1, 'utf8mb4' => 4]);
		(new MigrationPlanner())->plan([$this->fixable($child), $this->fixable($parent)], $graph, $schema);

		self::assertSame('utf8mb4', $child->getCharset());
		self::assertSame('utf8mb4_general_ci', $child->getCollation());
	}

	public function testForeignKeyAlignDoesNotForceSingleByteLegacyChild(): void
	{
		$graph = $this->foreignKeyGraph('fk_child_parent', 'child', 'c', 'parent', 'p');

		$child = ColumnTargetChange::effective(
			'db',
			'child',
			'c',
			'varchar(20)',
			'latin1',
			'latin1_swedish_ci',
			false,
			null,
			false,
			null,
			null,
			20,
			null,
		);
		$parent = ColumnTargetChange::effective(
			'db',
			'parent',
			'p',
			'varchar(20)',
			'utf8mb4',
			'utf8mb4_general_ci',
			false,
			null,
			false,
			null,
			null,
			20,
			null,
		);

		// latin1 (MAXLEN 1) is a single-byte legacy charset: even though widening to utf8mb4 (MAXLEN 4) is
		// non-narrowing, forcing it here would skip the collation auditor's LegacyCharsetConversion gate and its
		// binary two-step, so alignment must leave the child latin1.
		$schema = new SchemaContext([], ['latin1' => 1, 'utf8mb4' => 4]);
		(new MigrationPlanner())->plan([$this->fixable($child), $this->fixable($parent)], $graph, $schema);

		self::assertSame('latin1', $child->getCharset());
		self::assertSame('latin1_swedish_ci', $child->getCollation());
	}

	public function testForeignKeyAlignSameCharsetAlignsCollation(): void
	{
		$graph = $this->foreignKeyGraph('fk_child_parent', 'child', 'c', 'parent', 'p');

		$child = ColumnTargetChange::effective(
			'db',
			'child',
			'c',
			'varchar(20)',
			'utf8mb4',
			'utf8mb4_general_ci',
			false,
			null,
			false,
			null,
			null,
			20,
			null,
		);
		$parent = ColumnTargetChange::effective(
			'db',
			'parent',
			'p',
			'varchar(20)',
			'utf8mb4',
			'utf8mb4_czech_ci',
			false,
			null,
			false,
			null,
			null,
			20,
			null,
		);

		// Same charset, differing collation: realigning the collation re-encodes nothing, so the child adopts the
		// parent collation unconditionally.
		$schema = new SchemaContext([], ['utf8mb4' => 4]);
		(new MigrationPlanner())->plan([$this->fixable($child), $this->fixable($parent)], $graph, $schema);

		self::assertSame('utf8mb4', $child->getCharset());
		self::assertSame('utf8mb4_czech_ci', $child->getCollation());
	}

	public function testForeignKeyAlignWidensChildToParentLength(): void
	{
		$graph = $this->foreignKeyGraph('fk_child_parent', 'child', 'c', 'parent', 'p');

		$child = ColumnTargetChange::effective(
			'db',
			'child',
			'c',
			'varchar(20)',
			'utf8mb4',
			'utf8mb4_general_ci',
			false,
			null,
			false,
			null,
			null,
			20,
			null,
		);
		$parent = ColumnTargetChange::effective(
			'db',
			'parent',
			'p',
			'varchar(30)',
			'utf8mb4',
			'utf8mb4_general_ci',
			false,
			null,
			false,
			null,
			null,
			30,
			null,
		);

		(new MigrationPlanner())->plan([$this->fixable($child), $this->fixable($parent)], $graph);

		self::assertSame('varchar(30)', $child->getType());
		self::assertSame(30, $child->getEffectiveCharLength());
		self::assertSame('varchar(30)', $parent->getType());
		self::assertSame(30, $parent->getEffectiveCharLength());
	}

	public function testForeignKeyAlignDoesNotShrinkWiderChild(): void
	{
		$graph = $this->foreignKeyGraph('fk_child_parent', 'child', 'c', 'parent', 'p');

		$child = ColumnTargetChange::effective(
			'db',
			'child',
			'c',
			'varchar(40)',
			'utf8mb4',
			'utf8mb4_general_ci',
			false,
			null,
			false,
			null,
			null,
			40,
			null,
		);
		$parent = ColumnTargetChange::effective(
			'db',
			'parent',
			'p',
			'varchar(30)',
			'utf8mb4',
			'utf8mb4_general_ci',
			false,
			null,
			false,
			null,
			null,
			30,
			null,
		);

		(new MigrationPlanner())->plan([$this->fixable($child), $this->fixable($parent)], $graph);

		self::assertSame('varchar(40)', $child->getType());
		self::assertSame(40, $child->getEffectiveCharLength());
	}

	public function testForeignKeyAlignWidensChainToWidestParent(): void
	{
		// A -> B -> C: only C is widest; A and B must both widen transitively to the fixpoint.
		$graph = new ForeignKeyGraph([
			[
				'CONSTRAINT_NAME' => 'fk_a_b',
				'TABLE_NAME' => 'a',
				'COLUMN_NAME' => 'x',
				'ORDINAL_POSITION' => 1,
				'REFERENCED_TABLE_NAME' => 'b',
				'REFERENCED_COLUMN_NAME' => 'y',
				'UPDATE_RULE' => 'NO ACTION',
				'DELETE_RULE' => 'NO ACTION',
			],
			[
				'CONSTRAINT_NAME' => 'fk_b_c',
				'TABLE_NAME' => 'b',
				'COLUMN_NAME' => 'y',
				'ORDINAL_POSITION' => 1,
				'REFERENCED_TABLE_NAME' => 'c',
				'REFERENCED_COLUMN_NAME' => 'z',
				'UPDATE_RULE' => 'NO ACTION',
				'DELETE_RULE' => 'NO ACTION',
			],
		]);

		$a = ColumnTargetChange::effective(
			'db',
			'a',
			'x',
			'varchar(10)',
			'utf8mb4',
			'utf8mb4_general_ci',
			false,
			null,
			false,
			null,
			null,
			10,
			null,
		);
		$b = ColumnTargetChange::effective(
			'db',
			'b',
			'y',
			'varchar(20)',
			'utf8mb4',
			'utf8mb4_general_ci',
			false,
			null,
			false,
			null,
			null,
			20,
			null,
		);
		$c = ColumnTargetChange::effective(
			'db',
			'c',
			'z',
			'varchar(30)',
			'utf8mb4',
			'utf8mb4_general_ci',
			false,
			null,
			false,
			null,
			null,
			30,
			null,
		);

		(new MigrationPlanner())->plan([$this->fixable($a), $this->fixable($b), $this->fixable($c)], $graph);

		self::assertSame('varchar(30)', $a->getType());
		self::assertSame(30, $a->getEffectiveCharLength());
		self::assertSame('varchar(30)', $b->getType());
		self::assertSame(30, $b->getEffectiveCharLength());
		self::assertSame('varchar(30)', $c->getType());
		self::assertSame(30, $c->getEffectiveCharLength());
	}

	public function testForeignKeyAlignLeavesHalfChangedPairUntouched(): void
	{
		$graph = $this->foreignKeyGraph('fk_child_parent', 'child', 'c', 'parent', 'p');

		$child = ColumnTargetChange::effective(
			'db',
			'child',
			'c',
			'varchar(20)',
			'utf8mb3',
			'utf8mb3_general_ci',
			false,
			null,
			false,
			null,
			null,
			20,
			null,
		);

		// Only the child endpoint has a change; alignment skips the pair (the reconcile/drop pass owns it).
		(new MigrationPlanner())->plan([$this->fixable($child)], $graph);

		self::assertSame('varchar(20)', $child->getType());
		self::assertSame('utf8mb3', $child->getCharset());
		self::assertSame('utf8mb3_general_ci', $child->getCollation());
		self::assertSame(20, $child->getEffectiveCharLength());
	}

	private function foreignKeyGraph(
		string $name,
		string $childTable,
		string $childColumn,
		string $parentTable,
		string $parentColumn
	): ForeignKeyGraph
	{
		return new ForeignKeyGraph([
			[
				'CONSTRAINT_NAME' => $name,
				'TABLE_NAME' => $childTable,
				'COLUMN_NAME' => $childColumn,
				'ORDINAL_POSITION' => 1,
				'REFERENCED_TABLE_NAME' => $parentTable,
				'REFERENCED_COLUMN_NAME' => $parentColumn,
				'UPDATE_RULE' => 'NO ACTION',
				'DELETE_RULE' => 'NO ACTION',
			],
		]);
	}

	private function utf8mb3Table(string $column): TableSchema
	{
		return new TableSchema(
			[$column => $this->col(20, 'varchar', 'utf8mb3', 'utf8mb3_czech_ci')],
			[],
			'DYNAMIC',
		);
	}

	/**
	 * @return array{type: string, charset: string|null, collation: string|null, nullable: bool, default: array{text: string, isExpression: bool}|null, onUpdateCurrentTimestamp: bool, comment: string|null, generated: array{expression: string, stored: bool}|null, charLength: int|null, dataType: string}
	 */
	private function col(?int $charLength, string $dataType, ?string $charset, ?string $collation): array
	{
		$type = $charLength !== null ? $dataType . '(' . $charLength . ')' : $dataType;

		return [
			'type' => $type,
			'charset' => $charset,
			'collation' => $collation,
			'nullable' => false,
			'default' => null,
			'onUpdateCurrentTimestamp' => false,
			'comment' => null,
			'generated' => null,
			'charLength' => $charLength,
			'dataType' => $dataType,
		];
	}

	private function utf8mb4Change(string $table, string $column): ColumnTargetChange
	{
		return ColumnTargetChange::effective(
			'db',
			$table,
			$column,
			'varchar(20)',
			'utf8mb4',
			'utf8mb4_czech_ci',
			false,
			null,
			false,
			null,
			null,
			20,
			null,
		);
	}

	public function testNoSchemaSkipsRowFormatPass(): void
	{
		$plan = (new MigrationPlanner())->plan(
			[$this->fixable(
				ColumnTargetChange::effective(
					'db',
					'city',
					'name',
					'varchar(300)',
					'utf8mb4',
					'utf8mb4_general_ci',
					false,
					null,
					false,
					null,
					null,
					300,
					null,
				),
			)],
		);

		self::assertSame(
			"ALTER TABLE `city` MODIFY `name` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL;\n",
			(new BasicMigrationStrategy(new FakeDbalAdapter()))->render($plan),
		);
	}

	public function testAutoUpgradeOffSkipsRowFormatBump(): void
	{
		$schema = new SchemaContext([
			'city' => new TableSchema(
				['name' => $this->col(300, 'varchar', 'utf8mb3', 'utf8mb3_general_ci')],
				[['name' => 'uq', 'unique' => true, 'members' => [['column' => 'name', 'subPart' => null]]]],
				'COMPACT',
			),
		], ['utf8mb3' => 3, 'utf8mb4' => 4]);

		$plan = (new MigrationPlanner(false))->plan(
			[$this->fixable(
				ColumnTargetChange::effective(
					'db',
					'city',
					'name',
					'varchar(300)',
					'utf8mb4',
					'utf8mb4_general_ci',
					false,
					null,
					false,
					null,
					null,
					300,
					null,
				),
			)],
			null,
			$schema,
		);

		$sql = (new BasicMigrationStrategy(new FakeDbalAdapter()))->render($plan);
		// autoUpgradeRowFormat=false: column is planned without the bump, no refusal.
		self::assertStringContainsString('MODIFY `name`', $sql);
		self::assertStringNotContainsString('ROW_FORMAT = DYNAMIC', $sql);
		self::assertSame([], $plan->getRefusals());
		self::assertSame(1, $plan->getGeneratedCount());
	}

	/**
	 * @param list<Violation> $violations
	 */
	private function plan(array $violations): ResolvedPlan
	{
		return (new MigrationPlanner())->plan($violations);
	}

	private function render(ResolvedPlan $plan): string
	{
		return (new BasicMigrationStrategy(new FakeDbalAdapter()))->render($plan);
	}

	private function fixable(ChangeRequest $change): Violation
	{
		return new Violation(
			'demo.fixable',
			'fixable',
			new TableViolationSource($change->getDatabase(), null, $change->getTable()),
			true,
			null,
			[$change],
		);
	}

}

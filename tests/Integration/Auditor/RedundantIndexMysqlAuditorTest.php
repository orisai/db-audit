<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Auditor;

use Generator;
use Orisai\DbAudit\Auditor\RedundantIndexMysqlAuditor;
use Orisai\DbAudit\Change\DropIndexChange;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Report\TableViolationSource;
use Orisai\DbAudit\Report\Violation;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\DbProvider;
use Tests\Orisai\DbAudit\Helper\MysqlShortcuts;

final class RedundantIndexMysqlAuditorTest extends TestCase
{

	protected function tearDown(): void
	{
		parent::tearDown();
		DbProvider::disconnectAll();
	}

	/**
	 * @return Generator<string, array{0: DbalAdapter, 1: DatabaseEngine}>
	 */
	public function provide(): Generator
	{
		yield from DbProvider::adapters();
	}

	/**
	 * @dataProvider provide
	 */
	public function testEmptyDatabase(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$auditor = new RedundantIndexMysqlAuditor($dbal);

		$db = 'redundant_index_empty';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		self::assertEquals([], $auditor->analyse()->getViolations());
	}

	/**
	 * @dataProvider provide
	 */
	public function testExactDuplicateNonUnique(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$auditor = new RedundantIndexMysqlAuditor($dbal);
		$key = 'redundant_index';

		$db = 'redundant_index_duplicate';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		$dbal->exec(
		/** @lang MySQL */
			'CREATE TABLE `dup` (`a` int NOT NULL, INDEX `idx_a` (`a`), INDEX `idx_a_dup` (`a`))',
		);

		self::assertEquals([
			new Violation(
				$key,
				"Index 'idx_a_dup' on table [dup] is redundant: its columns are a prefix of index 'idx_a'.",
				new TableViolationSource($db, null, 'dup'),
				true,
				"Drop the redundant index 'idx_a_dup'.",
				[new DropIndexChange($db, 'dup', 'idx_a_dup')],
			),
		], $auditor->analyse()->getViolations());
	}

	/**
	 * @dataProvider provide
	 */
	public function testPrefixRedundant(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$auditor = new RedundantIndexMysqlAuditor($dbal);
		$key = 'redundant_index';

		$db = 'redundant_index_prefix';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		$dbal->exec(
		/** @lang MySQL */
			'CREATE TABLE `pref` (`a` int NOT NULL, `b` int NOT NULL, INDEX `idx_a` (`a`), INDEX `idx_ab` (`a`, `b`))',
		);

		self::assertEquals([
			new Violation(
				$key,
				"Index 'idx_a' on table [pref] is redundant: its columns are a prefix of index 'idx_ab'.",
				new TableViolationSource($db, null, 'pref'),
				true,
				"Drop the redundant index 'idx_a'.",
				[new DropIndexChange($db, 'pref', 'idx_a')],
			),
		], $auditor->analyse()->getViolations());
	}

	/**
	 * @dataProvider provide
	 */
	public function testCoveredRegardlessOfName(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$auditor = new RedundantIndexMysqlAuditor($dbal);
		$key = 'redundant_index';

		$db = 'redundant_index_covered';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		// The covered single-column index sorts AFTER its covering index, proving a proper prefix is flagged
		// independently of name order (the name tie-break only applies to exact duplicates).
		$dbal->exec(
		/** @lang MySQL */
			'CREATE TABLE `cov` (`a` int NOT NULL, `b` int NOT NULL, INDEX `idx_ab` (`a`, `b`), INDEX `idx_z` (`a`))',
		);

		self::assertEquals([
			new Violation(
				$key,
				"Index 'idx_z' on table [cov] is redundant: its columns are a prefix of index 'idx_ab'.",
				new TableViolationSource($db, null, 'cov'),
				true,
				"Drop the redundant index 'idx_z'.",
				[new DropIndexChange($db, 'cov', 'idx_z')],
			),
		], $auditor->analyse()->getViolations());
	}

	/**
	 * @dataProvider provide
	 */
	public function testUniquePrefixNotFlagged(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$auditor = new RedundantIndexMysqlAuditor($dbal);

		$db = 'redundant_index_unique';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		// uq_a (unique, single column) is a prefix of idx_ab, but a unique index enforces a constraint the
		// covering non-unique index does not, so it must never be flagged as redundant.
		$dbal->exec(
		/** @lang MySQL */
			'CREATE TABLE `uniq` (`a` int NOT NULL, `b` int NOT NULL, UNIQUE KEY `uq_a` (`a`), INDEX `idx_ab` (`a`, `b`))',
		);

		self::assertEquals([], $auditor->analyse()->getViolations());
	}

	/**
	 * @dataProvider provide
	 */
	public function testPrimaryNeverFlagged(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$auditor = new RedundantIndexMysqlAuditor($dbal);

		$db = 'redundant_index_primary';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		// PRIMARY (a) is a prefix of idx_ab (a, b) yet must never be flagged; idx_ab is longer so it is not
		// covered either.
		$dbal->exec(
		/** @lang MySQL */
			'CREATE TABLE `prim` (`a` int NOT NULL, `b` int NOT NULL, PRIMARY KEY (`a`), INDEX `idx_ab` (`a`, `b`))',
		);

		self::assertEquals([], $auditor->analyse()->getViolations());
	}

	/**
	 * @dataProvider provide
	 */
	public function testNonOverlappingNotFlagged(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$auditor = new RedundantIndexMysqlAuditor($dbal);

		$db = 'redundant_index_disjoint';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		$dbal->exec(
		/** @lang MySQL */
			'CREATE TABLE `disjoint` (`a` int NOT NULL, `b` int NOT NULL, INDEX `idx_a` (`a`), INDEX `idx_b` (`b`))',
		);

		self::assertEquals([], $auditor->analyse()->getViolations());
	}

	/**
	 * @dataProvider provide
	 */
	public function testPrefixLengthDifferenceNotRedundant(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$auditor = new RedundantIndexMysqlAuditor($dbal);

		$db = 'redundant_index_subpart';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		// idx_prefix indexes only the first 10 chars (SUB_PART = 10) while idx_full indexes the whole column
		// (SUB_PART = NULL); the (COLUMN_NAME, SUB_PART) elements differ, so neither covers the other.
		$dbal->exec(
		/** @lang MySQL */
			'CREATE TABLE `subpart` (`v` varchar(50) NOT NULL, INDEX `idx_full` (`v`), INDEX `idx_prefix` (`v`(10)))',
		);

		self::assertEquals([], $auditor->analyse()->getViolations());
	}

}

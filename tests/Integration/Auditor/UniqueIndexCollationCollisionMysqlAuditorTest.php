<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Auditor;

use Generator;
use Orisai\DbAudit\AnalyserCategory;
use Orisai\DbAudit\Auditor\UniqueIndexCollationCollisionMysqlAuditor;
use Orisai\DbAudit\Collation\CollationTargetPolicy;
use Orisai\DbAudit\Collation\OutdatedCollationConfig;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Report\ColumnViolationSource;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\DbProvider;
use Tests\Orisai\DbAudit\Helper\MysqlShortcuts;

final class UniqueIndexCollationCollisionMysqlAuditorTest extends TestCase
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

	private function modernizeConfig(): OutdatedCollationConfig
	{
		return (new OutdatedCollationConfig())->setTargetPolicy(CollationTargetPolicy::modernize());
	}

	private function setUpDatabase(MysqlShortcuts $shortcuts, string $db): void
	{
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);
	}

	/**
	 * A utf8mb3_general_ci unique column: under modernize the target (utf8mb4_0900_as_ci on MySQL,
	 * utf8mb4_unicode_520_ci on MariaDB) is a full UCA collation that equates the compatibility character
	 * U+00B3 (superscript three) with '3', while utf8mb3_general_ci keeps them distinct. Both rows therefore
	 * coexist before the migration but collide after it.
	 */
	private function createCollidingPair(DbalAdapter $dbal): void
	{
		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `t` (
	`code` varchar(20) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL,
	UNIQUE KEY `uq_code` (`code`)
) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci
SQL,
		);
		// 0x33 = '3'; 0xC2B3 = U+00B3 superscript three. Distinct under utf8mb3_general_ci, equal under UCA.
		$dbal->exec(
		/** @lang MySQL */
			"INSERT INTO `t` (`code`) VALUES (UNHEX('33')), (UNHEX('C2B3'))",
		);
	}

	/**
	 * @dataProvider provide
	 */
	public function testCategoryIsData(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$auditor = new UniqueIndexCollationCollisionMysqlAuditor($dbal);

		self::assertEquals(AnalyserCategory::data(), $auditor->getCategory());
		self::assertNotEquals(AnalyserCategory::structure(), $auditor->getCategory());
	}

	/**
	 * @dataProvider provide
	 */
	public function testCollidingData(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$this->setUpDatabase($shortcuts, 'uic_colliding');

		$auditor = new UniqueIndexCollationCollisionMysqlAuditor($dbal, $this->modernizeConfig());

		// Empty database: nothing to probe.
		$empty = $auditor->analyse()->getViolations();
		self::assertSame([], $empty);

		$this->createCollidingPair($dbal);

		$violations = $auditor->analyse()->getViolations();

		// Deterministic and repeatable.
		self::assertEquals($violations, $auditor->analyse()->getViolations());

		self::assertCount(1, $violations);
		$violation = $violations[0];
		self::assertSame('unique_index_collation_collision', $violation->getKey());
		self::assertFalse($violation->isFixable());
		self::assertSame([], $violation->getChanges());

		$hint = $violation->getHint();
		self::assertNotNull($hint);
		self::assertStringContainsString('forceUniqueIndexConversion', $hint);

		$message = $violation->getMessage();
		self::assertStringContainsString('uq_code', $message);
		self::assertStringContainsString('code', $message);

		$source = $violation->getSource();
		self::assertInstanceOf(ColumnViolationSource::class, $source);
		self::assertSame('t', $source->getTable());
		self::assertSame('code', $source->getColumn());
	}

	/**
	 * @dataProvider provide
	 */
	public function testNonCollidingData(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$this->setUpDatabase($shortcuts, 'uic_non_colliding');

		$auditor = new UniqueIndexCollationCollisionMysqlAuditor($dbal, $this->modernizeConfig());

		// Same schema as the colliding case, but values that stay distinct under the target collation.
		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `t` (
	`code` varchar(20) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NULL,
	UNIQUE KEY `uq_code` (`code`)
) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci
SQL,
		);
		$dbal->exec(
		/** @lang MySQL */
			"INSERT INTO `t` (`code`) VALUES ('apple'), ('banana')",
		);

		self::assertSame([], $auditor->analyse()->getViolations());
	}

	/**
	 * @dataProvider provide
	 */
	public function testOrderPreservingConversionNeverProbed(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$this->setUpDatabase($shortcuts, 'uic_order_preserving');

		// Default config = preserveOrder: utf8mb3_general_ci -> its utf8mb4_general_ci namesake is
		// order-preserving and can never collide, so the colliding pair is never probed.
		$auditor = new UniqueIndexCollationCollisionMysqlAuditor($dbal);

		$this->createCollidingPair($dbal);

		self::assertSame([], $auditor->analyse()->getViolations());
	}

	/**
	 * @dataProvider provide
	 */
	public function testNonStringUniqueIndex(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$this->setUpDatabase($shortcuts, 'uic_non_string');

		$auditor = new UniqueIndexCollationCollisionMysqlAuditor($dbal, $this->modernizeConfig());

		// An integer unique index has no charset to convert, so it is never probed.
		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `t` (
	`id` int NOT NULL,
	UNIQUE KEY `uq_id` (`id`)
)
SQL,
		);
		$dbal->exec('INSERT INTO `t` (`id`) VALUES (1), (2)');

		self::assertSame([], $auditor->analyse()->getViolations());
	}

}

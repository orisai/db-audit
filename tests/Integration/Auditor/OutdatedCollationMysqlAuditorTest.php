<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Auditor;

use Generator;
use Orisai\DbAudit\Auditor\NullableWithNoNullsMysqlAuditor;
use Orisai\DbAudit\Auditor\OutdatedCollationMysqlAuditor;
use Orisai\DbAudit\Change\AlterLock;
use Orisai\DbAudit\Change\BasicMigrationStrategy;
use Orisai\DbAudit\Collation\CollationTargetPolicy;
use Orisai\DbAudit\Collation\DatabaseDefaultHandling;
use Orisai\DbAudit\Collation\LegacyCharsetConversion;
use Orisai\DbAudit\Collation\OutdatedCollationConfig;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Ignore\IgnoredError;
use Orisai\DbAudit\Ignore\IgnoreList;
use Orisai\DbAudit\Report\Advisory;
use Orisai\DbAudit\Report\Violation;
use Orisai\DbAudit\Runner\Runner;
use Orisai\DbAudit\Schema\TableExclude;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\DbProvider;
use Tests\Orisai\DbAudit\Helper\MysqlShortcuts;
use function explode;
use function implode;
use function rtrim;
use function stripos;
use function strlen;
use function strncmp;
use function strpos;
use function strtolower;
use function substr;
use function substr_count;
use function trim;

final class OutdatedCollationMysqlAuditorTest extends TestCase
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
	public function testUtf8mb3Columns(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'outdated_collation_basic';
		$this->setUpDatabase($dbal, $db);
		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		// Beyond plain varchar/text, the table covers the column-definition reconstruction branches most
		// likely to differ between engines: a literal string DEFAULT with a multibyte value (MySQL
		// reports it unquoted, MariaDB pre-quoted), COMMENT preservation, an ENUM value list + default,
		// and a generated STORED string column (whose GENERATION_EXPRESSION each engine reports
		// differently — MySQL with a charset introducer and backslash-escaped quotes).
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `article` (
	`id` int NOT NULL,
	`title` varchar(255) NOT NULL,
	`body` text NULL,
	`slug` varchar(50) NOT NULL DEFAULT 'výchozí',
	`note` varchar(120) NULL DEFAULT NULL COMMENT 'Poznámka k článku',
	`kind` enum('novy','stary','archiv') NOT NULL DEFAULT 'novy',
	`label` varchar(40) GENERATED ALWAYS AS (CONCAT('x-', `title`)) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		// A 4-byte emoji cannot be stored in (nor sent over a connection using) utf8mb3,
		// so only the accented multibyte data exists before the migration.
		$dbal->exec(
			'INSERT INTO `article` (`id`, `title`, `body`, `note`) '
			. "VALUES (1, 'Příliš žluťoučký', 'accented text', 'Žádná poznámka')",
		);

		$before = $auditor->analyse()->getViolations();
		self::assertNotSame([], $before);

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		self::assertNotSame('', $sql);
		$this->runScript($dbal, $sql);

		// Idempotent: every converted column — including the enum and the generated column — must end at
		// the utf8mb4 target, otherwise re-analyse would still report it.
		self::assertSame([], $auditor->analyse()->getViolations());

		$row = $dbal->query(
			'SELECT `title`, `body`, `slug`, `note`, `kind`, `label` FROM `article` WHERE `id` = 1',
		)[0];
		// Existing accented row data is byte-identical after the conversion.
		self::assertSame('Příliš žluťoučký', $row['title']);
		self::assertSame('accented text', $row['body']);
		self::assertSame('Žádná poznámka', $row['note']);
		// The string DEFAULT was applied at INSERT time and survives the conversion.
		self::assertSame('výchozí', $row['slug']);
		self::assertSame('novy', $row['kind']);
		// The generated column recomputed from the (converted) title.
		self::assertSame('x-Příliš žluťoučký', $row['label']);

		$columns = $this->fetchColumns($dbal);

		// Every text-bearing column is now utf8mb4.
		self::assertSame('utf8mb4', $columns['title']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb4', $columns['body']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb4', $columns['slug']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb4', $columns['note']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb4', $columns['kind']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb4', $columns['label']['CHARACTER_SET_NAME']);

		// slug: the literal string DEFAULT round-trips to the same value on both engines. MySQL reports
		// COLUMN_DEFAULT unquoted ("výchozí"), MariaDB pre-quoted ("'výchozí'"); normalise both.
		self::assertSame('výchozí', $this->normaliseDefault($columns['slug']['COLUMN_DEFAULT']));

		// note: COMMENT preserved.
		self::assertSame('Poznámka k článku', $columns['note']['COLUMN_COMMENT']);

		// kind: ENUM value list preserved in COLUMN_TYPE, default preserved.
		self::assertSame("enum('novy','stary','archiv')", $columns['kind']['COLUMN_TYPE']);
		self::assertSame('novy', $this->normaliseDefault($columns['kind']['COLUMN_DEFAULT']));

		// label: still a generated column whose expression references `title`.
		self::assertStringContainsString('title', (string) $columns['label']['GENERATION_EXPRESSION']);

		// The columns are now utf8mb4, so 4-byte characters can be stored and read back intact.
		$dbal->exec('SET NAMES utf8mb4');
		$dbal->exec("INSERT INTO `article` (`id`, `title`, `body`) VALUES (2, 'x', 'emoji 😀 text')");
		$emojiRow = $dbal->query('SELECT `body` FROM `article` WHERE `id` = 2')[0];
		self::assertSame('emoji 😀 text', $emojiRow['body']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testUniqueIndexOrderPreservingConverts(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		// Under the default preserveOrder policy a unique-indexed column's target is order-preserving
		// (utf8mb3_czech_ci -> utf8mb4_czech_ci), so it converts via a plain MODIFY — no index drop/re-add.
		$db = 'outdated_collation_uq_nocollision';
		$this->setUpDatabase($dbal, $db);
		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `account` (
	`id` int NOT NULL,
	`login` varchar(100) NOT NULL,
	UNIQUE KEY `uq_login` (`login`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec("INSERT INTO `account` (`id`, `login`) VALUES (1, 'alice'), (2, 'bob')");

		$plan = $auditor->analyse()->getViolations();
		self::assertNotSame([], $plan);

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		self::assertStringContainsString('MODIFY `login`', $sql);
		self::assertStringNotContainsString('DROP INDEX', $sql);
		$this->runScript($dbal, $sql);

		self::assertSame([], $auditor->analyse()->getViolations());

		$rows = $dbal->query('SELECT `id`, `login` FROM `account` ORDER BY `id`');
		self::assertSame('alice', $rows[0]['login']);
		self::assertSame('bob', $rows[1]['login']);

		$columns = $this->fetchColumnsOf($dbal, 'account');
		self::assertSame('utf8mb4', $columns['login']['CHARACTER_SET_NAME']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testUniqueIndexNonOrderPreservingRefused(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		// A sensitivity-changing target (modernize *_ci) under a unique index could equate keys the source
		// kept distinct, so the conversion is REFUSED deterministically — no data probe. (Whether the data
		// actually collides is the Stage 5 data auditor's question, not this schema-only structure path.)
		$db = 'outdated_collation_uq_collision';
		$this->setUpDatabase($dbal, $db);

		$config = new OutdatedCollationConfig();
		$config->setTargetPolicy(CollationTargetPolicy::modernize());
		$auditor = new OutdatedCollationMysqlAuditor($dbal, $config);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `term` (
	`id` int NOT NULL,
	`code` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
	UNIQUE KEY `uq_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci
SQL,
		);
		$dbal->exec("INSERT INTO `term` (`id`, `code`) VALUES (1, 'alpha'), (2, 'beta')");

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		// The refused column is left out of the generated migration entirely.
		self::assertStringNotContainsString('MODIFY `code`', $sql);
		self::assertStringNotContainsString('DROP INDEX', $sql);

		$violations = $auditor->analyse()->getViolations();
		$refusal = $this->findViolationByKey($violations, 'outdated_collation.unique_index');
		self::assertNotNull($refusal);
		self::assertStringContainsString('[code]', $refusal->getMessage());
		self::assertStringContainsString('forceUniqueIndexConversion', $refusal->getMessage());

		// The emitted SQL must still be replayable (it only touches the safe DDL).
		$this->runScript($dbal, $sql);
	}

	/**
	 * @dataProvider provide
	 */
	public function testForceUniqueIndexConversion(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		// Without setForceUniqueIndexConversion a non-order-preserving (modernize) target always
		// refuses a unique-indexed column (deterministic, schema-only). With the flag set to true
		// the column converts; the migration applies cleanly on data that does not collide under
		// the target collation.

		// Sub-case 1 (always executed): without force the column is refused.
		$dbRefused = 'outdated_collation_force_uq_refused';
		$this->setUpDatabase($dbal, $dbRefused);

		$config = new OutdatedCollationConfig();
		$config->setTargetPolicy(CollationTargetPolicy::modernize());
		$auditor = new OutdatedCollationMysqlAuditor($dbal, $config);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `product` (
	`id` int NOT NULL,
	`sku` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
	UNIQUE KEY `uq_sku` (`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci
SQL,
		);
		// Values that stay distinct under every utf8mb4 ci collation — no collision on either engine.
		$dbal->exec("INSERT INTO `product` (`id`, `sku`) VALUES (1, 'alpha'), (2, 'beta')");

		$refusedReport = (new Runner($dbal, [$auditor]))->generate();
		$refusal = $this->findViolationByKey($refusedReport->getUnfixable(), 'outdated_collation.unique_index');
		self::assertNotNull($refusal);
		self::assertStringContainsString('[sku]', $refusal->getMessage());
		self::assertStringNotContainsString('MODIFY `sku`', $refusedReport->getSql());

		// Sub-case 2: with setForceUniqueIndexConversion(true) the column converts.
		$dbForced = 'outdated_collation_force_uq_forced';
		$this->setUpDatabase($dbal, $dbForced);

		$config2 = new OutdatedCollationConfig();
		$config2->setTargetPolicy(CollationTargetPolicy::modernize());
		$config2->setForceUniqueIndexConversion(true);
		$auditor2 = new OutdatedCollationMysqlAuditor($dbal, $config2);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `product` (
	`id` int NOT NULL,
	`sku` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
	UNIQUE KEY `uq_sku` (`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci
SQL,
		);
		$dbal->exec("INSERT INTO `product` (`id`, `sku`) VALUES (1, 'alpha'), (2, 'beta')");

		$forcedReport = (new Runner($dbal, [$auditor2]))->generate();
		$sql = $forcedReport->getSql();
		self::assertStringContainsString('MODIFY `sku`', $sql);
		self::assertNull($this->findViolationByKey($forcedReport->getUnfixable(), 'outdated_collation.unique_index'));
		$this->runScript($dbal, $sql);

		// Idempotent: re-analyse clean after forced conversion.
		self::assertSame([], $auditor2->analyse()->getViolations());

		$rows = $dbal->query('SELECT `id`, `sku` FROM `product` ORDER BY `id`');
		self::assertSame('alpha', $rows[0]['sku']);
		self::assertSame('beta', $rows[1]['sku']);

		$columns = $this->fetchColumnsOf($dbal, 'product');
		self::assertSame('utf8mb4', $columns['sku']['CHARACTER_SET_NAME']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testCompositeUniqueIndexConverts(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		// A column in a composite unique index converts under the default preserveOrder policy: its target
		// is order-preserving, so the conversion can never create a duplicate key regardless of the data
		// (here the same `code` legitimately repeats under different `group_id`). Plain MODIFY, no index churn.
		$db = 'outdated_collation_composite_uq';
		$this->setUpDatabase($dbal, $db);
		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `feature` (
	`group_id` int NOT NULL,
	`code` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_czech_ci NOT NULL,
	UNIQUE KEY `uq_group_code` (`group_id`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		// Same `code` under different `group_id`: `code` alone has duplicates, but the pair is unique.
		$dbal->exec(
			'INSERT INTO `feature` (`group_id`, `code`) '
			. "VALUES (1, 'alpha'), (2, 'alpha'), (1, 'beta'), (2, 'beta')",
		);

		$plan = $auditor->analyse()->getViolations();
		self::assertNotSame([], $plan);
		// `code` is reported as a plain outdated column, NOT refused under the unique index.
		$violation = $this->findColumnViolation($plan, 'code');
		self::assertNotNull($violation);
		self::assertNull($this->findViolationByKey($plan, 'outdated_collation.unique_index'));

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		// The composite-unique column IS converted (order-preserving target -> never a duplicate key).
		self::assertStringContainsString('MODIFY `code`', $sql);
		self::assertStringNotContainsString('DROP INDEX', $sql);
		$this->runScript($dbal, $sql);

		// Round-trip clean: re-analyse reports nothing.
		self::assertSame([], $auditor->analyse()->getViolations());

		$rows = $dbal->query('SELECT `group_id`, `code` FROM `feature` ORDER BY `group_id`, `code`');
		self::assertSame('alpha', $rows[0]['code']);
		self::assertSame('beta', $rows[1]['code']);
		self::assertSame('alpha', $rows[2]['code']);
		self::assertSame('beta', $rows[3]['code']);

		$columns = $this->fetchColumnsOf($dbal, 'feature');
		self::assertSame('utf8mb4', $columns['code']['CHARACTER_SET_NAME']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testPreserveOrderUniqueIndexConverts(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		// Under the default preserveOrder policy a utf8mb3 column converts to its utf8mb4 namesake
		// (here utf8mb3_bin -> utf8mb4_bin): the stored bytes and the collation algorithm are unchanged, so
		// two previously-distinct values can never collide and the column converts unconditionally. The data
		// below ('A' vs 'a') is distinct under bin yet WOULD collide under any case-insensitive collation —
		// proving the rule keys on order-preservation, never on the values themselves.
		$db = 'outdated_collation_preserveorder_uq';
		$this->setUpDatabase($dbal, $db);
		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `term` (
	`id` int NOT NULL,
	`code` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_bin NOT NULL,
	UNIQUE KEY `uq_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec('SET NAMES utf8mb4');
		// Distinct under utf8mb3_bin (so both rows store under the unique index); they would collide under
		// a case-insensitive target, but the namesake utf8mb4_bin keeps them distinct.
		$dbal->exec("INSERT INTO `term` (`id`, `code`) VALUES (1, 'A'), (2, 'a')");

		$plan = $auditor->analyse()->getViolations();
		self::assertNotSame([], $plan);
		// The column is reported as a plain outdated column, NOT refused under the unique index.
		$violation = $this->findColumnViolation($plan, 'code');
		self::assertNotNull($violation);
		self::assertNull($this->findViolationByKey($plan, 'outdated_collation.unique_index'));

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		// The unique-index column IS converted (order-preserving target -> no collision possible).
		self::assertStringContainsString('MODIFY `code`', $sql);
		self::assertStringNotContainsString('DROP INDEX', $sql);
		$this->runScript($dbal, $sql);

		// Round-trip clean: re-analyse reports nothing.
		self::assertSame([], $auditor->analyse()->getViolations());

		$rows = $dbal->query('SELECT `id`, `code` FROM `term` ORDER BY `id`');
		self::assertSame('A', $rows[0]['code']);
		self::assertSame('a', $rows[1]['code']);

		$columns = $this->fetchColumnsOf($dbal, 'term');
		self::assertSame('utf8mb4', $columns['code']['CHARACTER_SET_NAME']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testNullableUniqueIndexConverts(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		// A nullable unique-indexed column converts under the default preserveOrder policy: its target is
		// order-preserving, so the conversion is data-independent (the multiple NULL rows a UNIQUE index
		// permits are irrelevant — there is no data probe to be confused by them).
		$db = 'outdated_collation_uq_nullable';
		$this->setUpDatabase($dbal, $db);
		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `member` (
	`id` int NOT NULL,
	`nick` varchar(100) NULL,
	UNIQUE KEY `uq_nick` (`nick`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		// Two NULL rows (allowed under the unique index) plus distinct non-null multibyte values.
		$dbal->exec(
			'INSERT INTO `member` (`id`, `nick`) '
			. "VALUES (1, NULL), (2, NULL), (3, 'Žluťoučký'), (4, 'Příliš')",
		);

		$plan = $auditor->analyse()->getViolations();
		self::assertNotSame([], $plan);

		// The column must NOT be refused under the unique index (it is a plain outdated-column report).
		$violation = $this->findColumnViolation($plan, 'nick');
		self::assertNotNull($violation);
		self::assertNull($this->findViolationByKey($plan, 'outdated_collation.unique_index'));

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		// The nullable unique column IS converted (order-preserving target -> never a duplicate key).
		self::assertStringContainsString('MODIFY `nick`', $sql);
		self::assertStringNotContainsString('DROP INDEX', $sql);
		$this->runScript($dbal, $sql);

		// Round-trip clean: re-analyse reports nothing.
		self::assertSame([], $auditor->analyse()->getViolations());

		$rows = $dbal->query('SELECT `id`, `nick` FROM `member` ORDER BY `id`');
		self::assertNull($rows[0]['nick']);
		self::assertNull($rows[1]['nick']);
		self::assertSame('Žluťoučký', $rows[2]['nick']);
		self::assertSame('Příliš', $rows[3]['nick']);

		$columns = $this->fetchColumnsOf($dbal, 'member');
		self::assertSame('utf8mb4', $columns['nick']['CHARACTER_SET_NAME']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testPrimaryKeyNonOrderPreservingRefused(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		// A PRIMARY KEY is a unique index too: a PK-backed string column converting to a sensitivity-changing
		// target (modernize *_ci) is REFUSED deterministically — never emitted as an unchecked MODIFY, and
		// never a DROP/ADD of the PRIMARY index.
		$db = 'outdated_collation_pk_collision';
		$this->setUpDatabase($dbal, $db);

		$config = new OutdatedCollationConfig();
		$config->setTargetPolicy(CollationTargetPolicy::modernize());
		$auditor = new OutdatedCollationMysqlAuditor($dbal, $config);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `glossary` (
	`code` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
	PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci
SQL,
		);
		$dbal->exec("INSERT INTO `glossary` (`code`) VALUES ('cz'), ('sk')");

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		// The refused PK column is left out of the generated migration entirely; no MODIFY and never a
		// DROP/ADD of the PRIMARY index.
		self::assertStringNotContainsString('MODIFY `code`', $sql);
		self::assertStringNotContainsString('PRIMARY', $sql);

		$violations = $auditor->analyse()->getViolations();
		$refusal = $this->findViolationByKey($violations, 'outdated_collation.unique_index');
		self::assertNotNull($refusal);
		self::assertStringContainsString('[code]', $refusal->getMessage());
		self::assertStringContainsString('forceUniqueIndexConversion', $refusal->getMessage());

		// Whatever DDL remains is still replayable.
		$this->runScript($dbal, $sql);
	}

	/**
	 * @dataProvider provide
	 */
	public function testPrimaryKeyOrderPreservingConverts(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		// A PK-backed string column converts cleanly under the default preserveOrder policy (order-preserving
		// target) — a plain MODIFY, never a DROP/ADD of the PRIMARY index.
		$db = 'outdated_collation_pk_nocollision';
		$this->setUpDatabase($dbal, $db);
		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `country` (
	`code` varchar(50) NOT NULL,
	PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec("INSERT INTO `country` (`code`) VALUES ('cz'), ('sk')");

		$plan = $auditor->analyse()->getViolations();
		self::assertNotSame([], $plan);
		// Reported as a plain outdated column, not refused under the PRIMARY KEY.
		$violation = $this->findColumnViolation($plan, 'code');
		self::assertNotNull($violation);
		self::assertNull($this->findViolationByKey($plan, 'outdated_collation.unique_index'));

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		self::assertStringContainsString('MODIFY `code`', $sql);
		self::assertStringNotContainsString('PRIMARY', $sql);
		self::assertStringNotContainsString('DROP INDEX', $sql);
		$this->runScript($dbal, $sql);

		// Round-trip clean.
		self::assertSame([], $auditor->analyse()->getViolations());

		$rows = $dbal->query('SELECT `code` FROM `country` ORDER BY `code`');
		self::assertSame('cz', $rows[0]['code']);
		self::assertSame('sk', $rows[1]['code']);

		$columns = $this->fetchColumnsOf($dbal, 'country');
		self::assertSame('utf8mb4', $columns['code']['CHARACTER_SET_NAME']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testUniqueIndexConvertsWithoutRecreatingIndex(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		// Both members of the composite unique index are order-preserving under the default policy, so each
		// converts via a plain MODIFY and the index is never dropped/re-added — it rides through untouched.
		$db = 'outdated_collation_uq_recreate';
		$this->setUpDatabase($dbal, $db);
		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `tag` (
	`id` int NOT NULL,
	`name` varchar(100) NOT NULL,
	`scope` varchar(200) NOT NULL,
	UNIQUE KEY `uq_name_scope` (`name`, `scope`(20))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec(
			"INSERT INTO `tag` (`id`, `name`, `scope`) VALUES (1, 'red', 'global'), (2, 'blue', 'local')",
		);

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		self::assertStringContainsString('MODIFY `name`', $sql);
		self::assertStringContainsString('MODIFY `scope`', $sql);
		self::assertStringNotContainsString('DROP INDEX', $sql);
		self::assertStringNotContainsString('ADD UNIQUE INDEX', $sql);
		$this->runScript($dbal, $sql);

		self::assertSame([], $auditor->analyse()->getViolations());

		// The unique index rides through the conversion unchanged.
		$indexRows = $dbal->query(
			'SELECT COLUMN_NAME, SUB_PART, NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS '
			. "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tag' AND INDEX_NAME = 'uq_name_scope' "
			. 'ORDER BY SEQ_IN_INDEX',
		);
		self::assertCount(2, $indexRows);
		self::assertSame(0, (int) $indexRows[0]['NON_UNIQUE']);
		self::assertSame('name', $indexRows[0]['COLUMN_NAME']);
		self::assertSame('scope', $indexRows[1]['COLUMN_NAME']);
		self::assertSame(20, (int) $indexRows[1]['SUB_PART']);

		$columns = $this->fetchColumnsOf($dbal, 'tag');
		self::assertSame('utf8mb4', $columns['name']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb4', $columns['scope']['CHARACTER_SET_NAME']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testCompactRowFormatUpgraded(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'outdated_collation_compact';
		$this->setUpDatabase($dbal, $db);
		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		// COMPACT/REDUNDANT cap an index prefix at 767 bytes: varchar(255) utf8mb3 = 765 bytes fits before,
		// but the utf8mb4 conversion would need 1020 bytes — only a DYNAMIC row format makes that legal.
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `entry` (
	`id` int NOT NULL,
	`name` varchar(255) NOT NULL,
	KEY `k_name` (`name`)
) ENGINE=InnoDB ROW_FORMAT=COMPACT DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec("INSERT INTO `entry` (`id`, `name`) VALUES (1, 'hello'), (2, 'world')");

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		self::assertStringContainsString('ROW_FORMAT = DYNAMIC', $sql);
		$this->runScript($dbal, $sql);

		self::assertSame([], $auditor->analyse()->getViolations());

		$rowFormat = $dbal->query(
			'SELECT ROW_FORMAT FROM INFORMATION_SCHEMA.TABLES '
			. "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'entry'",
		)[0];
		self::assertSame('Dynamic', $rowFormat['ROW_FORMAT']);

		$columns = $this->fetchColumnsOf($dbal, 'entry');
		self::assertSame('utf8mb4', $columns['name']['CHARACTER_SET_NAME']);
	}

	/**
	 * COMPACT/REDUNDANT cap each indexed column at 767 bytes PER COLUMN (verified on MySQL 8.0 and
	 * MariaDB 11.4), not the summed key length. A composite unique index whose every member stays under
	 * 767 bytes after the utf8mb4 conversion therefore needs NO ROW_FORMAT bump: two utf8mb3 varchar(100)
	 * columns become 400 bytes each (well under 767), summing to 800 — which the summed model wrongly read
	 * as over 767 and over-bumped to DYNAMIC. Both columns convert and the table stays COMPACT.
	 *
	 * @dataProvider provide
	 */
	public function testCompactCompositeIndexEachColumnFitsNoRowFormatBump(
		DbalAdapter $dbal,
		DatabaseEngine $engine
	): void
	{
		$db = 'outdated_collation_compact_percol';
		$this->setUpDatabase($dbal, $db);
		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `combo` (
	`id` int NOT NULL,
	`a` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_czech_ci NOT NULL,
	`b` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_czech_ci NOT NULL,
	PRIMARY KEY (`id`),
	UNIQUE KEY `uq_ab` (`a`, `b`)
) ENGINE=InnoDB ROW_FORMAT=COMPACT DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec("INSERT INTO `combo` (`id`, `a`, `b`) VALUES (1, 'x', 'y'), (2, 'p', 'q')");

		// The table really was created as COMPACT (the precondition the planner must not disturb).
		$created = $dbal->query(
			'SELECT ROW_FORMAT FROM INFORMATION_SCHEMA.TABLES '
			. "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'combo'",
		)[0];
		self::assertSame('Compact', $created['ROW_FORMAT']);

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		// Each converted column is 400 bytes < 767, so no DYNAMIC bump is needed — the summed 800 is irrelevant.
		self::assertStringNotContainsString('ROW_FORMAT = DYNAMIC', $sql);
		self::assertStringContainsString('MODIFY `a`', $sql);
		self::assertStringContainsString('MODIFY `b`', $sql);

		$this->runScript($dbal, $sql);

		self::assertSame([], $auditor->analyse()->getViolations());

		// The table stayed COMPACT — the conversion applied without a row-format change.
		$rowFormat = $dbal->query(
			'SELECT ROW_FORMAT FROM INFORMATION_SCHEMA.TABLES '
			. "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'combo'",
		)[0];
		self::assertSame('Compact', $rowFormat['ROW_FORMAT']);

		$columns = $this->fetchColumnsOf($dbal, 'combo');
		self::assertSame('utf8mb4', $columns['a']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb4', $columns['b']['CHARACTER_SET_NAME']);
	}

	/**
	 * A converting column that crosses 767 bytes after the utf8mb4 conversion forces ROW_FORMAT = DYNAMIC
	 * on a COMPACT table, even when it shares a composite unique index with an already-utf8mb4 sibling.
	 *
	 * Sizes: under COMPACT (767-byte PER-COLUMN cap) the index fits at creation — utf8mb3 `a` = 255*3 =
	 * 765 bytes <= 767, utf8mb4 `b` = 50*4 = 200 bytes <= 767. Converting `a` to utf8mb4 makes it 255*4 =
	 * 1020 bytes > 767, so a single member column now exceeds the COMPACT cap and the migration MUST add
	 * ROW_FORMAT = DYNAMIC; the small utf8mb4 sibling is left alone and the recreated index stays valid.
	 *
	 * (Under the per-column model this no longer exercises the old R14 "sibling bytes counted in the sum"
	 * path — a utf8mb4 sibling over 767 bytes cannot exist on a COMPACT table, so the bump can only come
	 * from the converting column itself crossing 767. The new
	 * testCompactCompositeIndexEachColumnFitsNoRowFormatBump covers the no-bump-when-each-fits direction.)
	 *
	 * @dataProvider provide
	 */
	public function testCompactIndexWithUtf8mb4SiblingColumnUpgradesRowFormat(
		DbalAdapter $dbal,
		DatabaseEngine $engine
	): void
	{
		$db = 'outdated_collation_compact_sibling';
		$this->setUpDatabase($dbal, $db);
		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `combo` (
	`id` int NOT NULL,
	`a` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_czech_ci NOT NULL,
	`b` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
	PRIMARY KEY (`id`),
	UNIQUE KEY `uq_ab` (`a`, `b`)
) ENGINE=InnoDB ROW_FORMAT=COMPACT DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec("INSERT INTO `combo` (`id`, `a`, `b`) VALUES (1, 'x', 'y'), (2, 'p', 'q')");

		// The table really was created as COMPACT (the precondition for the upgrade).
		$created = $dbal->query(
			'SELECT ROW_FORMAT FROM INFORMATION_SCHEMA.TABLES '
			. "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'combo'",
		)[0];
		self::assertSame('Compact', $created['ROW_FORMAT']);

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		// `a` converts to 1020 bytes > 767, exceeding the COMPACT per-column cap, so DYNAMIC is required.
		self::assertStringContainsString('ROW_FORMAT = DYNAMIC', $sql);
		self::assertStringContainsString('MODIFY `a`', $sql);

		// The script applies cleanly — proving the converted index fits under the DYNAMIC budget.
		$this->runScript($dbal, $sql);

		self::assertSame([], $auditor->analyse()->getViolations());

		$rowFormat = $dbal->query(
			'SELECT ROW_FORMAT FROM INFORMATION_SCHEMA.TABLES '
			. "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'combo'",
		)[0];
		self::assertSame('Dynamic', $rowFormat['ROW_FORMAT']);

		$columns = $this->fetchColumnsOf($dbal, 'combo');
		// The utf8mb3 column converted; the sibling stays utf8mb4 (left alone under preserve-order).
		self::assertSame('utf8mb4', $columns['a']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb4', $columns['b']['CHARACTER_SET_NAME']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testIndexTooLongEvenDynamic(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'outdated_collation_toolong';
		$this->setUpDatabase($dbal, $db);

		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		// varchar(1000) utf8mb3 = 3000 bytes, creatable under DYNAMIC (3072 limit); the utf8mb4 conversion
		// would require 4000 bytes — beyond the hard 3072-byte key limit no row format can satisfy.
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `lookup` (
	`id` int NOT NULL,
	`big` varchar(1000) NOT NULL,
	UNIQUE KEY `uq_big` (`big`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);

		$report = (new Runner($dbal, [$auditor]))->generate();
		$sql = $report->getSql();
		// The over-long column is excluded so no failing statement is produced.
		self::assertStringNotContainsString('MODIFY `big`', $sql);

		// The >3072 key-length refusal now comes from the planner as a change.index_too_long unfixable.
		$tooLong = $this->findColumnViolation($report->getUnfixable(), 'big');
		self::assertNotNull($tooLong);
		self::assertStringContainsString('3072-byte key length limit', $tooLong);

		// Whatever DDL remains is still replayable.
		$this->runScript($dbal, $sql);
	}

	/**
	 * @dataProvider provide
	 */
	public function testDatabaseDefaultEmittedWhenGranted(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'outdated_collation_dbdefault_granted';
		$this->setUpDatabase($dbal, $db);
		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `note` (
	`id` int NOT NULL,
	`text` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		self::assertStringContainsString('ALTER DATABASE', $sql);

		$this->runScript($dbal, $sql);
		self::assertSame([], $auditor->analyse()->getViolations());

		$default = $dbal->query(
			'SELECT DEFAULT_CHARACTER_SET_NAME AS charset FROM INFORMATION_SCHEMA.SCHEMATA '
			. 'WHERE SCHEMA_NAME = DATABASE()',
		)[0];
		self::assertSame('utf8mb4', $default['charset']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testDatabaseDefaultSkippedByConfig(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'outdated_collation_dbdefault_skip';
		$this->setUpDatabase($dbal, $db);

		$config = new OutdatedCollationConfig();
		$config->setDatabaseDefault(DatabaseDefaultHandling::skip());
		$auditor = new OutdatedCollationMysqlAuditor($dbal, $config);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `note` (
	`id` int NOT NULL,
	`text` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		self::assertStringNotContainsString('ALTER DATABASE', $sql);

		$advisory = $this->findAdvisoryContaining($auditor->analyse()->getAdvisories(), 'skipped by configuration');
		self::assertNotNull($advisory);
	}

	/**
	 * @dataProvider provide
	 */
	public function testDatabaseDefaultSkippedWhenNoGrant(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'outdated_collation_dbdefault_nogrant';
		$this->setUpDatabase($dbal, $db);

		// A restricted account that holds only SELECT (no ALTER) on the test database. Analysis still
		// connects as root (root can read another account's grants); the grant gate must skip ALTER DATABASE.
		$dbal->exec("DROP USER IF EXISTS 'dbaudit_limited'@'%'");
		$dbal->exec("CREATE USER IF NOT EXISTS 'dbaudit_limited'@'%' IDENTIFIED BY 'x'");
		$grantDb = $dbal->escapeIdentifier($db);
		// phpcs:ignore SlevomatCodingStandard.PHP.RequireExplicitAssertion.RequiredExplicitAssertion
		/** @var literal-string $grantSql */
		$grantSql = "GRANT SELECT ON $grantDb.* TO 'dbaudit_limited'@'%'";
		$dbal->exec($grantSql);

		try {
			$config = new OutdatedCollationConfig();
			$config->setExecutionAccount('dbaudit_limited@%');
			$auditor = new OutdatedCollationMysqlAuditor($dbal, $config);

			$dbal->exec(
				<<<'SQL'
CREATE TABLE `note` (
	`id` int NOT NULL,
	`text` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
			);

			$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
			self::assertStringNotContainsString('ALTER DATABASE', $sql);

			$advisory = $this->findAdvisoryContaining($auditor->analyse()->getAdvisories(), 'missing ALTER privilege');
			self::assertNotNull($advisory);
		} finally {
			$dbal->exec("DROP USER IF EXISTS 'dbaudit_limited'@'%'");
		}
	}

	/**
	 * @dataProvider provide
	 */
	public function testLockDefaultEmitsNoClause(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'outdated_collation_lock_default';
		$this->setUpDatabase($dbal, $db);
		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `note` (
	`id` int NOT NULL,
	`text` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		self::assertStringContainsString('MODIFY `text`', $sql);
		self::assertStringNotContainsString('LOCK =', $sql);
	}

	/**
	 * @dataProvider provide
	 */
	public function testLockSharedEmitsClause(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'outdated_collation_lock_shared';
		$this->setUpDatabase($dbal, $db);

		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `note` (
	`id` int NOT NULL,
	`text` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);

		$sql = (new Runner(
			$dbal,
			[$auditor],
			null,
			null,
			new BasicMigrationStrategy($dbal, AlterLock::shared()),
		))->generate()->getSql();
		self::assertStringContainsString('LOCK = SHARED', $sql);
		self::assertStringNotContainsString('LOCK = EXCLUSIVE', $sql);

		// Every emitted ALTER TABLE statement ends with the shared lock clause.
		foreach (explode(";\n", $sql) as $rawStatement) {
			$statement = rtrim(trim($rawStatement), ';');
			if (stripos($statement, 'ALTER TABLE') === 0) {
				self::assertStringEndsWith('LOCK = SHARED', $statement);
			}
		}

		$this->runScript($dbal, $sql);
		self::assertSame([], $auditor->analyse()->getViolations());

		$columns = $this->fetchColumnsOf($dbal, 'note');
		self::assertSame('utf8mb4', $columns['text']['CHARACTER_SET_NAME']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testLockNoneEmitsNoLockClause(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'outdated_collation_lock_none';
		$this->setUpDatabase($dbal, $db);

		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `note` (
	`id` int NOT NULL,
	`text` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		self::assertStringContainsString('MODIFY `text`', $sql);
		self::assertStringNotContainsString('LOCK = NONE', $sql);

		// LOCK=NONE advisory dropped in the config split

		// The remaining DDL stays replayable.
		$this->runScript($dbal, $sql);
	}

	/**
	 * @dataProvider provide
	 */
	public function testForeignKeyPairConverted(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'outdated_collation_fk_pair';
		$this->setUpDatabase($dbal, $db);
		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `country` (
	`code` varchar(10) NOT NULL,
	PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `city` (
	`id` int NOT NULL,
	`country_code` varchar(10) NOT NULL,
	PRIMARY KEY (`id`),
	CONSTRAINT `fk_city_country` FOREIGN KEY (`country_code`) REFERENCES `country` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec("INSERT INTO `country` (`code`) VALUES ('cz')");
		$dbal->exec("INSERT INTO `city` (`id`, `country_code`) VALUES (1, 'cz')");

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		self::assertStringContainsString('MODIFY `code`', $sql);
		self::assertStringContainsString('MODIFY `country_code`', $sql);
		$this->runScript($dbal, $sql);

		self::assertSame([], $auditor->analyse()->getViolations());

		// Both endpoints converted to utf8mb4.
		self::assertSame('utf8mb4', $this->fetchColumnsOf($dbal, 'country')['code']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb4', $this->fetchColumnsOf($dbal, 'city')['country_code']['CHARACTER_SET_NAME']);

		// The foreign key still exists after the migration.
		$constraints = $dbal->query(
			'SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS '
			. "WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'city'",
		);
		self::assertNotSame([], $constraints);

		// An FK-respecting insert still works; a violating one still fails.
		$dbal->exec("INSERT INTO `country` (`code`) VALUES ('sk')");
		$dbal->exec("INSERT INTO `city` (`id`, `country_code`) VALUES (2, 'sk')");
		$rows = $dbal->query('SELECT `id`, `country_code` FROM `city` ORDER BY `id`');
		self::assertSame('cz', $rows[0]['country_code']);
		self::assertSame('sk', $rows[1]['country_code']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testForeignKeyUniqueEndpointConvertsAndRebuilds(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		// A string FK whose referenced column sits under the PRIMARY KEY (a unique index) while the
		// referencing column sits under the non-unique auto FK index. Under the default preserveOrder policy
		// the PK endpoint's target is order-preserving, so both endpoints convert and the FK is dropped and
		// re-added around the conversion (the rebuilt constraint matches: utf8mb4 child and parent).
		$db = 'outdated_collation_fk_unique_converts';
		$this->setUpDatabase($dbal, $db);
		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `country` (
	`code` varchar(20) NOT NULL,
	PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `city` (
	`id` int NOT NULL,
	`country_code` varchar(20) NOT NULL,
	PRIMARY KEY (`id`),
	KEY `k_country_code` (`country_code`),
	CONSTRAINT `fk_city_country` FOREIGN KEY (`country_code`) REFERENCES `country` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec("INSERT INTO `country` (`code`) VALUES ('cz')");
		$dbal->exec("INSERT INTO `city` (`id`, `country_code`) VALUES (1, 'cz')");

		$report = (new Runner($dbal, [$auditor]))->generate();
		$sql = $report->getSql();
		// Both endpoints convert and the FK is rebuilt around the conversion.
		self::assertStringContainsString('MODIFY `code`', $sql);
		self::assertStringContainsString('MODIFY `country_code`', $sql);
		self::assertStringContainsString('DROP FOREIGN KEY `fk_city_country`', $sql);
		self::assertStringContainsString('ADD CONSTRAINT `fk_city_country`', $sql);

		// No unique-index refusal under the order-preserving default policy.
		self::assertNull($this->findViolationByKey($report->getUnfixable(), 'outdated_collation.unique_index'));

		$this->runScript($dbal, $sql);

		// Both endpoints are now utf8mb4.
		self::assertSame('utf8mb4', $this->fetchColumnsOf($dbal, 'country')['code']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb4', $this->fetchColumnsOf($dbal, 'city')['country_code']['CHARACTER_SET_NAME']);

		// The foreign key still exists.
		$constraints = $dbal->query(
			'SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS '
			. "WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'city'",
		);
		self::assertNotSame([], $constraints);
	}

	/**
	 * @dataProvider provide
	 */
	public function testForeignKeyUniqueEndpointRefusedKeepsBothEndpoints(
		DbalAdapter $dbal,
		DatabaseEngine $engine
	): void
	{
		// The referenced PRIMARY KEY column converts to a sensitivity-changing modernize target, so it is
		// REFUSED deterministically (a unique-index member under a non-order-preserving conversion). Its FK
		// partner on the child must therefore also be left unconverted and the FK must NOT be rebuilt — the
		// planner's FK-endpoint consistency holds the pair back as a unit.
		$db = 'outdated_collation_fk_detect_collision';
		$this->setUpDatabase($dbal, $db);

		$config = new OutdatedCollationConfig();
		$config->setTargetPolicy(CollationTargetPolicy::modernize());
		$auditor = new OutdatedCollationMysqlAuditor($dbal, $config);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `country` (
	`code` varchar(20) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
	PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci
SQL,
		);
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `city` (
	`id` int NOT NULL,
	`country_code` varchar(20) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
	PRIMARY KEY (`id`),
	KEY `k_country_code` (`country_code`),
	CONSTRAINT `fk_city_country` FOREIGN KEY (`country_code`) REFERENCES `country` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci
SQL,
		);
		$dbal->exec("INSERT INTO `country` (`code`) VALUES ('cz'), ('sk')");
		$dbal->exec("INSERT INTO `city` (`id`, `country_code`) VALUES (1, 'cz'), (2, 'sk')");

		$report = (new Runner($dbal, [$auditor]))->generate();
		$sql = $report->getSql();
		// Neither endpoint converts and the FK is left intact.
		self::assertStringNotContainsString('MODIFY `code`', $sql);
		self::assertStringNotContainsString('MODIFY `country_code`', $sql);
		self::assertStringNotContainsString('DROP FOREIGN KEY', $sql);

		$unfixable = $report->getUnfixable();
		// The parent key conversion is refused by the auditor on the referenced column.
		$refusal = $this->findViolationByKey($unfixable, 'outdated_collation.unique_index');
		self::assertNotNull($refusal);
		self::assertStringContainsString('[code]', $refusal->getMessage());
		self::assertStringContainsString('forceUniqueIndexConversion', $refusal->getMessage());
		// The planner reports the FK as left unconverted as a unit.
		$fkViolation = $this->findViolationContaining($unfixable, 'fk_city_country');
		self::assertNotNull($fkViolation);
		self::assertStringContainsString('left unconverted', $fkViolation);

		// The generated SQL applies cleanly — no ADD CONSTRAINT failure.
		$this->runScript($dbal, $sql);

		// Both endpoints stay utf8mb3 (intentionally unconverted).
		self::assertSame('utf8mb3', $this->fetchColumnsOf($dbal, 'country')['code']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb3', $this->fetchColumnsOf($dbal, 'city')['country_code']['CHARACTER_SET_NAME']);

		// The foreign key still exists.
		$constraints = $dbal->query(
			'SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS '
			. "WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'city'",
		);
		self::assertNotSame([], $constraints);
	}

	/**
	 * @dataProvider provide
	 */
	public function testForeignKeyActionsPreserved(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		// The FK is dropped and re-added around the column conversion; its referential actions must survive.
		// ON DELETE CASCADE / ON UPDATE SET NULL are sourced from REFERENTIAL_CONSTRAINTS and re-emitted, so
		// they must STILL hold after the migration (the child FK column is nullable to make SET NULL legal).
		$db = 'outdated_collation_fk_actions';
		$this->setUpDatabase($dbal, $db);
		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `country` (
	`code` varchar(10) NOT NULL,
	PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `city` (
	`id` int NOT NULL,
	`country_code` varchar(10) NULL,
	PRIMARY KEY (`id`),
	CONSTRAINT `fk_city_country` FOREIGN KEY (`country_code`) REFERENCES `country` (`code`)
		ON DELETE CASCADE ON UPDATE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec("INSERT INTO `country` (`code`) VALUES ('cz'), ('sk')");
		$dbal->exec("INSERT INTO `city` (`id`, `country_code`) VALUES (1, 'cz'), (2, 'sk')");

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		self::assertStringContainsString('DROP FOREIGN KEY `fk_city_country`', $sql);
		self::assertStringContainsString('ON DELETE CASCADE', $sql);
		self::assertStringContainsString('ON UPDATE SET NULL', $sql);
		$this->runScript($dbal, $sql);

		// Idempotent.
		self::assertSame([], $auditor->analyse()->getViolations());

		// Both endpoints converted to utf8mb4.
		self::assertSame('utf8mb4', $this->fetchColumnsOf($dbal, 'country')['code']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb4', $this->fetchColumnsOf($dbal, 'city')['country_code']['CHARACTER_SET_NAME']);

		// The constraint still exists with its original name and BOTH referential actions preserved.
		$rule = $dbal->query(
			'SELECT DELETE_RULE, UPDATE_RULE FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS '
			. "WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'city' "
			. "AND CONSTRAINT_NAME = 'fk_city_country'",
		);
		self::assertCount(1, $rule);
		self::assertSame('CASCADE', $rule[0]['DELETE_RULE']);
		self::assertSame('SET NULL', $rule[0]['UPDATE_RULE']);

		// The actions are live: deleting a parent row cascades to its child rows.
		$dbal->exec("DELETE FROM `country` WHERE `code` = 'cz'");
		$remaining = $dbal->query('SELECT `id` FROM `city` ORDER BY `id`');
		self::assertCount(1, $remaining);
		self::assertSame(2, (int) $remaining[0]['id']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testCompositeForeignKeyConverted(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		// A two-column FK is dropped and re-added around the conversion; its columns must come back in the
		// correct order (sourced from KEY_COLUMN_USAGE ordered by ORDINAL_POSITION). All four endpoint
		// columns converge to utf8mb4 so the re-added composite FK stays type-compatible.
		$db = 'outdated_collation_fk_composite';
		$this->setUpDatabase($dbal, $db);
		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `region` (
	`col_a` varchar(10) NOT NULL,
	`col_b` varchar(10) NOT NULL,
	PRIMARY KEY (`col_a`, `col_b`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `district` (
	`id` int NOT NULL,
	`a` varchar(10) NOT NULL,
	`b` varchar(10) NOT NULL,
	PRIMARY KEY (`id`),
	CONSTRAINT `fk_district_region` FOREIGN KEY (`a`, `b`) REFERENCES `region` (`col_a`, `col_b`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec("INSERT INTO `region` (`col_a`, `col_b`) VALUES ('cz', 'pr')");
		$dbal->exec("INSERT INTO `district` (`id`, `a`, `b`) VALUES (1, 'cz', 'pr')");

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		self::assertStringContainsString('DROP FOREIGN KEY `fk_district_region`', $sql);
		self::assertStringContainsString('FOREIGN KEY (`a`, `b`)', $sql);
		self::assertStringContainsString('REFERENCES `region` (`col_a`, `col_b`)', $sql);
		$this->runScript($dbal, $sql);

		// Idempotent.
		self::assertSame([], $auditor->analyse()->getViolations());

		// All four endpoint columns converted to utf8mb4.
		$regionColumns = $this->fetchColumnsOf($dbal, 'region');
		self::assertSame('utf8mb4', $regionColumns['col_a']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb4', $regionColumns['col_b']['CHARACTER_SET_NAME']);
		$districtColumns = $this->fetchColumnsOf($dbal, 'district');
		self::assertSame('utf8mb4', $districtColumns['a']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb4', $districtColumns['b']['CHARACTER_SET_NAME']);

		// The FK still exists with BOTH referencing columns in the correct order.
		$fkColumns = $dbal->query(
			'SELECT COLUMN_NAME, REFERENCED_COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE '
			. "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'district' "
			. "AND CONSTRAINT_NAME = 'fk_district_region' AND REFERENCED_TABLE_NAME IS NOT NULL "
			. 'ORDER BY ORDINAL_POSITION',
		);
		self::assertCount(2, $fkColumns);
		self::assertSame('a', $fkColumns[0]['COLUMN_NAME']);
		self::assertSame('col_a', $fkColumns[0]['REFERENCED_COLUMN_NAME']);
		self::assertSame('b', $fkColumns[1]['COLUMN_NAME']);
		self::assertSame('col_b', $fkColumns[1]['REFERENCED_COLUMN_NAME']);

		// An FK-respecting insert still works.
		$dbal->exec("INSERT INTO `region` (`col_a`, `col_b`) VALUES ('sk', 'ba')");
		$dbal->exec("INSERT INTO `district` (`id`, `a`, `b`) VALUES (2, 'sk', 'ba')");
		$rows = $dbal->query('SELECT `id`, `a`, `b` FROM `district` ORDER BY `id`');
		self::assertCount(2, $rows);
		self::assertSame('sk', $rows[1]['a']);
		self::assertSame('ba', $rows[1]['b']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testForeignKeyBothIncludedConverted(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		// Both tables in scope: the today behaviour. Both FK columns convert, the FK is dropped+recreated,
		// and re-analysis is clean (round-trip).
		$db = 'outdated_collation_fk_both_included';
		$this->setUpDatabase($dbal, $db);
		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `country` (
	`code` varchar(10) NOT NULL,
	PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `city` (
	`id` int NOT NULL,
	`country_code` varchar(10) NOT NULL,
	PRIMARY KEY (`id`),
	CONSTRAINT `fk_city_country` FOREIGN KEY (`country_code`) REFERENCES `country` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec("INSERT INTO `country` (`code`) VALUES ('cz')");
		$dbal->exec("INSERT INTO `city` (`id`, `country_code`) VALUES (1, 'cz')");

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		self::assertStringContainsString('MODIFY `code`', $sql);
		self::assertStringContainsString('MODIFY `country_code`', $sql);
		self::assertStringContainsString('DROP FOREIGN KEY `fk_city_country`', $sql);
		self::assertStringContainsString('ADD CONSTRAINT `fk_city_country`', $sql);
		$this->runScript($dbal, $sql);

		self::assertSame([], $auditor->analyse()->getViolations());

		self::assertSame('utf8mb4', $this->fetchColumnsOf($dbal, 'country')['code']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb4', $this->fetchColumnsOf($dbal, 'city')['country_code']['CHARACTER_SET_NAME']);

		$constraints = $dbal->query(
			'SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS '
			. "WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'city'",
		);
		self::assertNotSame([], $constraints);
	}

	/**
	 * @dataProvider provide
	 */
	public function testForeignKeyBoundaryChildIncludedParentExcludedConvertsBoth(
		DbalAdapter $dbal,
		DatabaseEngine $engine
	): void
	{
		// Child included, parent excluded: the FK relationship stays in scope. BOTH FK columns convert —
		// including the FK column on the EXCLUDED parent — so the constraint stays valid; the parent's OTHER
		// columns and its table default are left untouched. The FK is dropped+recreated.
		$db = 'outdated_collation_fk_boundary_parent_excluded';
		$this->setUpDatabase($dbal, $db);

		$config = new OutdatedCollationConfig();
		$config->setExcludeTables((new TableExclude())->withPattern('^country$'));
		$auditor = new OutdatedCollationMysqlAuditor($dbal, $config);

		// The excluded parent carries an extra non-FK column that must stay utf8mb3 (only its FK column `code`
		// is pulled into scope).
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `country` (
	`code` varchar(10) NOT NULL,
	`name` varchar(100) NOT NULL,
	PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `city` (
	`id` int NOT NULL,
	`country_code` varchar(10) NOT NULL,
	PRIMARY KEY (`id`),
	CONSTRAINT `fk_city_country` FOREIGN KEY (`country_code`) REFERENCES `country` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec("INSERT INTO `country` (`code`, `name`) VALUES ('cz', 'Czechia')");
		$dbal->exec("INSERT INTO `city` (`id`, `country_code`) VALUES (1, 'cz')");

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		// BOTH FK columns convert — the child's and the excluded parent's.
		self::assertStringContainsString('MODIFY `country_code`', $sql);
		self::assertStringContainsString('MODIFY `code`', $sql);
		// The FK is dropped and recreated.
		self::assertStringContainsString('DROP FOREIGN KEY `fk_city_country`', $sql);
		self::assertStringContainsString('ADD CONSTRAINT `fk_city_country`', $sql);
		// The excluded parent's NON-FK column must NOT be converted; the excluded parent's ALTER touches
		// only its FK column `code` (no DEFAULT CHARACTER SET clause for the excluded table).
		self::assertStringNotContainsString('MODIFY `name`', $sql);
		self::assertSame(
			'ALTER TABLE `country` MODIFY `code` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci NOT NULL;',
			$this->statementForTable($sql, 'country'),
		);
		// No "unfixable boundary" report any more.
		self::assertNull(
			$this->findViolationContaining(
				$auditor->analyse()->getViolations(),
				'crosses the table-exclusion boundary',
			),
		);

		$this->runScript($dbal, $sql);

		// Both FK columns are now utf8mb4; the excluded parent's other column AND its table default stay utf8mb3.
		$countryColumns = $this->fetchColumnsOf($dbal, 'country');
		self::assertSame('utf8mb4', $countryColumns['code']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb3', $countryColumns['name']['CHARACTER_SET_NAME']);
		self::assertStringStartsWith('utf8mb3', $this->tableCollationOf($dbal, 'country'));
		self::assertSame('utf8mb4', $this->fetchColumnsOf($dbal, 'city')['country_code']['CHARACTER_SET_NAME']);

		// The FK is still valid.
		$constraints = $dbal->query(
			'SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS '
			. "WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'city' "
			. "AND CONSTRAINT_NAME = 'fk_city_country'",
		);
		self::assertNotSame([], $constraints);

		// An FK-respecting insert works.
		$dbal->exec("INSERT INTO `country` (`code`, `name`) VALUES ('sk', 'Slovakia')");
		$dbal->exec("INSERT INTO `city` (`id`, `country_code`) VALUES (2, 'sk')");
		$rows = $dbal->query('SELECT `id`, `country_code` FROM `city` ORDER BY `id`');
		self::assertSame('cz', $rows[0]['country_code']);
		self::assertSame('sk', $rows[1]['country_code']);

		// Re-analyse: no boundary violation (the relationship was handled).
		self::assertNull(
			$this->findViolationContaining(
				$auditor->analyse()->getViolations(),
				'crosses the table-exclusion boundary',
			),
		);
	}

	/**
	 * @dataProvider provide
	 */
	public function testForeignKeyBoundaryParentIncludedChildExcludedConvertsBoth(
		DbalAdapter $dbal,
		DatabaseEngine $engine
	): void
	{
		// Symmetric mirror: parent included, child excluded (the boundary FK is reachable only through the
		// referenced-table index). BOTH FK columns convert — including the FK column on the EXCLUDED child —
		// while the excluded child's other column and table default stay untouched.
		$db = 'outdated_collation_fk_boundary_child_excluded';
		$this->setUpDatabase($dbal, $db);

		$config = new OutdatedCollationConfig();
		$config->setExcludeTables((new TableExclude())->withPattern('^_'));
		$auditor = new OutdatedCollationMysqlAuditor($dbal, $config);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `country` (
	`code` varchar(10) NOT NULL,
	PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		// The excluded child carries an extra non-FK column that must stay utf8mb3.
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `_city` (
	`id` int NOT NULL,
	`country_code` varchar(10) NOT NULL,
	`label` varchar(100) NOT NULL,
	PRIMARY KEY (`id`),
	CONSTRAINT `fk_city_country` FOREIGN KEY (`country_code`) REFERENCES `country` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec("INSERT INTO `country` (`code`) VALUES ('cz')");
		$dbal->exec("INSERT INTO `_city` (`id`, `country_code`, `label`) VALUES (1, 'cz', 'Prague')");

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		// BOTH FK columns convert — the parent's `code` and the excluded child's `country_code`.
		self::assertStringContainsString('MODIFY `code`', $sql);
		self::assertStringContainsString('MODIFY `country_code`', $sql);
		// The FK is dropped and recreated (the DROP/ADD runs on the excluded referencing child).
		self::assertStringContainsString('DROP FOREIGN KEY `fk_city_country`', $sql);
		self::assertStringContainsString('ADD CONSTRAINT `fk_city_country`', $sql);
		// The excluded child's NON-FK column is not converted.
		self::assertStringNotContainsString('MODIFY `label`', $sql);
		self::assertNull(
			$this->findViolationContaining(
				$auditor->analyse()->getViolations(),
				'crosses the table-exclusion boundary',
			),
		);

		$this->runScript($dbal, $sql);

		// Both FK columns are now utf8mb4; the excluded child's other column stays utf8mb3.
		self::assertSame('utf8mb4', $this->fetchColumnsOf($dbal, 'country')['code']['CHARACTER_SET_NAME']);
		$cityColumns = $this->fetchColumnsOf($dbal, '_city');
		self::assertSame('utf8mb4', $cityColumns['country_code']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb3', $cityColumns['label']['CHARACTER_SET_NAME']);

		// The FK is still valid.
		$constraints = $dbal->query(
			'SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS '
			. "WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = '_city' "
			. "AND CONSTRAINT_NAME = 'fk_city_country'",
		);
		self::assertNotSame([], $constraints);

		// An FK-respecting insert works.
		$dbal->exec("INSERT INTO `country` (`code`) VALUES ('sk')");
		$dbal->exec("INSERT INTO `_city` (`id`, `country_code`, `label`) VALUES (2, 'sk', 'Bratislava')");
		$rows = $dbal->query('SELECT `id`, `country_code` FROM `_city` ORDER BY `id`');
		self::assertSame('cz', $rows[0]['country_code']);
		self::assertSame('sk', $rows[1]['country_code']);

		self::assertNull(
			$this->findViolationContaining(
				$auditor->analyse()->getViolations(),
				'crosses the table-exclusion boundary',
			),
		);
	}

	/**
	 * @dataProvider provide
	 */
	public function testForeignKeyBothExcludedIgnored(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		// Both tables excluded: the FK relationship is ignored entirely — neither table's columns convert,
		// the FK is untouched, and nothing is reported.
		$db = 'outdated_collation_fk_both_excluded';
		$this->setUpDatabase($dbal, $db);

		$config = new OutdatedCollationConfig();
		$config->setExcludeTables((new TableExclude())->withPattern('^_'));
		$auditor = new OutdatedCollationMysqlAuditor($dbal, $config);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `_country` (
	`code` varchar(10) NOT NULL,
	PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `_city` (
	`id` int NOT NULL,
	`country_code` varchar(10) NOT NULL,
	PRIMARY KEY (`id`),
	CONSTRAINT `fk_city_country` FOREIGN KEY (`country_code`) REFERENCES `_country` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		// An in-scope table so the plan is not entirely empty.
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `article` (
	`id` int NOT NULL,
	`title` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);

		$violations = $auditor->analyse()->getViolations();
		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();

		// The in-scope table converts.
		self::assertStringContainsString('MODIFY `title`', $sql);
		// Neither excluded table is touched: no FK drop/recreate, no FK-column MODIFY.
		self::assertStringNotContainsString('`_country`', $sql);
		self::assertStringNotContainsString('`_city`', $sql);
		self::assertStringNotContainsString('MODIFY `country_code`', $sql);
		self::assertStringNotContainsString('DROP FOREIGN KEY', $sql);
		// No violations about either excluded table or the FK.
		self::assertNull($this->findViolationContaining($violations, '_country'));
		self::assertNull($this->findViolationContaining($violations, '_city'));
		self::assertNull($this->findViolationContaining($violations, 'fk_city_country'));

		$this->runScript($dbal, $sql);

		// The excluded tables stay utf8mb3 and the FK is still present.
		self::assertSame('utf8mb3', $this->fetchColumnsOf($dbal, '_country')['code']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb3', $this->fetchColumnsOf($dbal, '_city')['country_code']['CHARACTER_SET_NAME']);
		$constraints = $dbal->query(
			'SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS '
			. "WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = '_city'",
		);
		self::assertNotSame([], $constraints);
	}

	/**
	 * @dataProvider provide
	 */
	public function testCompositeForeignKeyBoundaryConvertsBoth(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		// A composite (two-column) FK across the boundary: both columns on both sides convert and the FK is
		// recreated with the correct column order. The parent is excluded; only its two FK columns are
		// pulled into scope.
		$db = 'outdated_collation_fk_boundary_composite';
		$this->setUpDatabase($dbal, $db);

		$config = new OutdatedCollationConfig();
		$config->setExcludeTables((new TableExclude())->withPattern('^region$'));
		$auditor = new OutdatedCollationMysqlAuditor($dbal, $config);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `region` (
	`col_a` varchar(10) NOT NULL,
	`col_b` varchar(10) NOT NULL,
	`note` varchar(100) NOT NULL,
	PRIMARY KEY (`col_a`, `col_b`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `district` (
	`id` int NOT NULL,
	`a` varchar(10) NOT NULL,
	`b` varchar(10) NOT NULL,
	PRIMARY KEY (`id`),
	CONSTRAINT `fk_district_region` FOREIGN KEY (`a`, `b`) REFERENCES `region` (`col_a`, `col_b`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec("INSERT INTO `region` (`col_a`, `col_b`, `note`) VALUES ('cz', 'pr', 'x')");
		$dbal->exec("INSERT INTO `district` (`id`, `a`, `b`) VALUES (1, 'cz', 'pr')");

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		// All four FK endpoint columns convert (two on the child, two on the excluded parent).
		self::assertStringContainsString('MODIFY `a`', $sql);
		self::assertStringContainsString('MODIFY `b`', $sql);
		self::assertStringContainsString('MODIFY `col_a`', $sql);
		self::assertStringContainsString('MODIFY `col_b`', $sql);
		// The excluded parent's NON-FK column stays out of scope.
		self::assertStringNotContainsString('MODIFY `note`', $sql);
		// The composite FK is dropped and recreated with correct column order.
		self::assertStringContainsString('DROP FOREIGN KEY `fk_district_region`', $sql);
		self::assertStringContainsString('FOREIGN KEY (`a`, `b`)', $sql);
		self::assertStringContainsString('REFERENCES `region` (`col_a`, `col_b`)', $sql);
		$this->runScript($dbal, $sql);

		// All four FK columns are now utf8mb4; the excluded parent's non-FK column stays utf8mb3.
		$regionColumns = $this->fetchColumnsOf($dbal, 'region');
		self::assertSame('utf8mb4', $regionColumns['col_a']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb4', $regionColumns['col_b']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb3', $regionColumns['note']['CHARACTER_SET_NAME']);
		$districtColumns = $this->fetchColumnsOf($dbal, 'district');
		self::assertSame('utf8mb4', $districtColumns['a']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb4', $districtColumns['b']['CHARACTER_SET_NAME']);

		// The FK is still valid with both referencing columns in the correct order.
		$fkColumns = $dbal->query(
			'SELECT COLUMN_NAME, REFERENCED_COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE '
			. "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'district' "
			. "AND CONSTRAINT_NAME = 'fk_district_region' AND REFERENCED_TABLE_NAME IS NOT NULL "
			. 'ORDER BY ORDINAL_POSITION',
		);
		self::assertCount(2, $fkColumns);
		self::assertSame('a', $fkColumns[0]['COLUMN_NAME']);
		self::assertSame('col_a', $fkColumns[0]['REFERENCED_COLUMN_NAME']);
		self::assertSame('b', $fkColumns[1]['COLUMN_NAME']);
		self::assertSame('col_b', $fkColumns[1]['REFERENCED_COLUMN_NAME']);

		// An FK-respecting insert works.
		$dbal->exec("INSERT INTO `region` (`col_a`, `col_b`, `note`) VALUES ('sk', 'ba', 'y')");
		$dbal->exec("INSERT INTO `district` (`id`, `a`, `b`) VALUES (2, 'sk', 'ba')");
		$rows = $dbal->query('SELECT `id`, `a`, `b` FROM `district` ORDER BY `id`');
		self::assertCount(2, $rows);
		self::assertSame('sk', $rows[1]['a']);
		self::assertSame('ba', $rows[1]['b']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testForeignKeyBoundaryExcludedTableInTwoBoundaryFks(
		DbalAdapter $dbal,
		DatabaseEngine $engine
	): void
	{
		// An excluded table `shared_ref` is referenced by TWO different included child tables through TWO
		// separate FKs on TWO separate columns of `shared_ref`. The accumulation set in applyForeignKeyBoundary
		// collects both FK columns before building a single TableMigration, so the excluded table's ALTER
		// TABLE must MODIFY both FK columns exactly once each — no duplicate MODIFY — while its non-FK column
		// `extra` stays unconverted.
		$db = 'outdated_collation_fk_boundary_shared_ref';
		$this->setUpDatabase($dbal, $db);

		$config = new OutdatedCollationConfig();
		$config->setExcludeTables((new TableExclude())->withPattern('^shared_ref$'));
		$auditor = new OutdatedCollationMysqlAuditor($dbal, $config);

		// Excluded parent: two FK-referenced columns plus one non-FK column that must stay utf8mb3.
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `shared_ref` (
	`code_a` varchar(10) NOT NULL,
	`code_b` varchar(10) NOT NULL,
	`extra` varchar(50) NOT NULL,
	UNIQUE KEY `uq_code_a` (`code_a`),
	UNIQUE KEY `uq_code_b` (`code_b`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		// First included child references `shared_ref`.`code_a`.
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `child_one` (
	`id` int NOT NULL,
	`ref_a` varchar(10) NOT NULL,
	PRIMARY KEY (`id`),
	CONSTRAINT `fk_child_one_ref` FOREIGN KEY (`ref_a`) REFERENCES `shared_ref` (`code_a`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		// Second included child references `shared_ref`.`code_b`.
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `child_two` (
	`id` int NOT NULL,
	`ref_b` varchar(10) NOT NULL,
	PRIMARY KEY (`id`),
	CONSTRAINT `fk_child_two_ref` FOREIGN KEY (`ref_b`) REFERENCES `shared_ref` (`code_b`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec("INSERT INTO `shared_ref` (`code_a`, `code_b`, `extra`) VALUES ('aa', 'bb', 'unchanged')");
		$dbal->exec("INSERT INTO `child_one` (`id`, `ref_a`) VALUES (1, 'aa')");
		$dbal->exec("INSERT INTO `child_two` (`id`, `ref_b`) VALUES (1, 'bb')");

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();

		// Both FK columns on the excluded `shared_ref` must be converted.
		self::assertStringContainsString('MODIFY `code_a`', $sql);
		self::assertStringContainsString('MODIFY `code_b`', $sql);
		// The non-FK column must NOT be converted.
		self::assertStringNotContainsString('MODIFY `extra`', $sql);
		// Both FKs are dropped and recreated.
		self::assertStringContainsString('DROP FOREIGN KEY `fk_child_one_ref`', $sql);
		self::assertStringContainsString('ADD CONSTRAINT `fk_child_one_ref`', $sql);
		self::assertStringContainsString('DROP FOREIGN KEY `fk_child_two_ref`', $sql);
		self::assertStringContainsString('ADD CONSTRAINT `fk_child_two_ref`', $sql);
		// The excluded table's ALTER TABLE must appear exactly once (no duplicate MODIFY from a double build).
		$sharedRefStatement = $this->statementForTable($sql, 'shared_ref');
		self::assertNotNull($sharedRefStatement);
		// Each FK column appears in the single shared_ref ALTER TABLE exactly once.
		self::assertSame(1, substr_count($sharedRefStatement, 'MODIFY `code_a`'));
		self::assertSame(1, substr_count($sharedRefStatement, 'MODIFY `code_b`'));

		$this->runScript($dbal, $sql);

		// Both FK columns on the excluded table are now utf8mb4; `extra` stays utf8mb3.
		$sharedRefColumns = $this->fetchColumnsOf($dbal, 'shared_ref');
		self::assertSame('utf8mb4', $sharedRefColumns['code_a']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb4', $sharedRefColumns['code_b']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb3', $sharedRefColumns['extra']['CHARACTER_SET_NAME']);

		// Both included children's FK columns converted.
		self::assertSame('utf8mb4', $this->fetchColumnsOf($dbal, 'child_one')['ref_a']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb4', $this->fetchColumnsOf($dbal, 'child_two')['ref_b']['CHARACTER_SET_NAME']);

		// Both FKs still valid: FK-respecting inserts work on both children.
		$dbal->exec("INSERT INTO `shared_ref` (`code_a`, `code_b`, `extra`) VALUES ('cc', 'dd', 'more')");
		$dbal->exec("INSERT INTO `child_one` (`id`, `ref_a`) VALUES (2, 'cc')");
		$dbal->exec("INSERT INTO `child_two` (`id`, `ref_b`) VALUES (2, 'dd')");
		$childOneRows = $dbal->query('SELECT `id`, `ref_a` FROM `child_one` ORDER BY `id`');
		self::assertCount(2, $childOneRows);
		self::assertSame('aa', $childOneRows[0]['ref_a']);
		self::assertSame('cc', $childOneRows[1]['ref_a']);
		$childTwoRows = $dbal->query('SELECT `id`, `ref_b` FROM `child_two` ORDER BY `id`');
		self::assertCount(2, $childTwoRows);
		self::assertSame('bb', $childTwoRows[0]['ref_b']);
		self::assertSame('dd', $childTwoRows[1]['ref_b']);

		// Re-analyse after migration must be clean.
		self::assertSame([], $auditor->analyse()->getViolations());
	}

	/**
	 * @dataProvider provide
	 */
	public function testForeignKeyBoundaryReportModeLatin1NotConverted(
		DbalAdapter $dbal,
		DatabaseEngine $engine
	): void
	{
		// A boundary FK whose two columns are single-byte legacy (latin1) under the default report() mode:
		// neither side converts (report mode leaves them unchanged), so there is no charset mismatch and the
		// FK is NOT dropped/recreated. The included child surfaces a latin1 report violation for its FK
		// column; the excluded parent's FK column stays UNREPORTED (the excluded table is out of scope for
		// reporting — only pulled in for conversion, which here does not happen).
		$db = 'outdated_collation_fk_boundary_latin1_report';
		$this->setUpDatabase($dbal, $db);

		$config = new OutdatedCollationConfig();
		$config->setExcludeTables((new TableExclude())->withPattern('^country$'));
		$auditor = new OutdatedCollationMysqlAuditor($dbal, $config);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `country` (
	`code` varchar(10) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
	PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `city` (
	`id` int NOT NULL,
	`country_code` varchar(10) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
	PRIMARY KEY (`id`),
	CONSTRAINT `fk_city_country` FOREIGN KEY (`country_code`) REFERENCES `country` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		// Neither side converts (report mode), so neither FK column is modified and the FK is NOT rebuilt.
		self::assertStringNotContainsString('MODIFY `country_code`', $sql);
		self::assertStringNotContainsString('MODIFY `code`', $sql);
		self::assertStringNotContainsString('DROP FOREIGN KEY', $sql);

		$violations = $auditor->analyse()->getViolations();
		// The included child's FK column surfaces a latin1 report violation.
		$childReport = $this->findColumnViolation($violations, 'country_code');
		self::assertNotNull($childReport);
		self::assertStringContainsString("legacy charset 'latin1'", $childReport);
		// The excluded parent's FK column stays unreported.
		self::assertNull($this->findColumnViolation($violations, 'code'));

		$this->runScript($dbal, $sql);

		// Both FK columns stay latin1; the FK is still present.
		self::assertSame('latin1', $this->fetchColumnsOf($dbal, 'country')['code']['CHARACTER_SET_NAME']);
		self::assertSame('latin1', $this->fetchColumnsOf($dbal, 'city')['country_code']['CHARACTER_SET_NAME']);
		$constraints = $dbal->query(
			'SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS '
			. "WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'city'",
		);
		self::assertNotSame([], $constraints);
	}

	/**
	 * @dataProvider provide
	 */
	public function testForeignKeyChildWidenedToParentLength(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		// The referencing (child) FK column is shorter than the referenced (parent) column (legal: a child may
		// be narrower). The conversion is parent-driven: the child must be WIDENED to the parent's length so
		// both ends share charset, collation AND a length where the parent is never wider than the child.
		$db = 'outdated_collation_fk_widen_child';
		$this->setUpDatabase($dbal, $db);
		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `parent` (
	`code` varchar(100) NOT NULL,
	PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `child` (
	`id` int NOT NULL,
	`parent_code` varchar(50) NOT NULL,
	PRIMARY KEY (`id`),
	KEY `k_parent_code` (`parent_code`),
	CONSTRAINT `fk_child_parent` FOREIGN KEY (`parent_code`) REFERENCES `parent` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec("INSERT INTO `parent` (`code`) VALUES ('cz')");
		$dbal->exec("INSERT INTO `child` (`id`, `parent_code`) VALUES (1, 'cz')");

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		// The child FK column's MODIFY widens it to the parent's length; the parent keeps its own length.
		self::assertStringContainsString('MODIFY `parent_code` varchar(100)', $sql);
		self::assertStringContainsString('MODIFY `code` varchar(100)', $sql);
		$this->runScript($dbal, $sql);

		// Round-trip clean.
		self::assertSame([], $auditor->analyse()->getViolations());

		// The child was widened to varchar(100) to match the parent; both ends are utf8mb4.
		self::assertSame('varchar(100)', $this->fetchColumnTypeOf($dbal, 'child', 'parent_code'));
		self::assertSame('varchar(100)', $this->fetchColumnTypeOf($dbal, 'parent', 'code'));
		self::assertSame('utf8mb4', $this->fetchColumnsOf($dbal, 'child')['parent_code']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb4', $this->fetchColumnsOf($dbal, 'parent')['code']['CHARACTER_SET_NAME']);

		// The foreign key is still present and enforced by an FK-respecting insert.
		$constraints = $dbal->query(
			'SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS '
			. "WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'child'",
		);
		self::assertNotSame([], $constraints);
		$dbal->exec("INSERT INTO `parent` (`code`) VALUES ('sk')");
		$dbal->exec("INSERT INTO `child` (`id`, `parent_code`) VALUES (2, 'sk')");
		$rows = $dbal->query('SELECT `id`, `parent_code` FROM `child` ORDER BY `id`');
		self::assertSame('cz', $rows[0]['parent_code']);
		self::assertSame('sk', $rows[1]['parent_code']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testForeignKeyChildLargerThanParentKeptLength(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		// The referencing (child) FK column is already WIDER than the referenced (parent) column. Length
		// alignment only ever widens — never shrinks — so the child keeps its larger length; the parent stays
		// at its own length (the parent is not wider than the child, so the invariant already holds).
		$db = 'outdated_collation_fk_keep_child';
		$this->setUpDatabase($dbal, $db);
		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `parent` (
	`code` varchar(50) NOT NULL,
	PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `child` (
	`id` int NOT NULL,
	`parent_code` varchar(100) NOT NULL,
	PRIMARY KEY (`id`),
	KEY `k_parent_code` (`parent_code`),
	CONSTRAINT `fk_child_parent` FOREIGN KEY (`parent_code`) REFERENCES `parent` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec("INSERT INTO `parent` (`code`) VALUES ('cz')");
		$dbal->exec("INSERT INTO `child` (`id`, `parent_code`) VALUES (1, 'cz')");

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		// The child is NOT shrunk; the parent keeps its own length.
		self::assertStringContainsString('MODIFY `parent_code` varchar(100)', $sql);
		self::assertStringContainsString('MODIFY `code` varchar(50)', $sql);
		$this->runScript($dbal, $sql);

		// Round-trip clean.
		self::assertSame([], $auditor->analyse()->getViolations());

		// The child stayed varchar(100); the parent stayed varchar(50); both ends utf8mb4.
		self::assertSame('varchar(100)', $this->fetchColumnTypeOf($dbal, 'child', 'parent_code'));
		self::assertSame('varchar(50)', $this->fetchColumnTypeOf($dbal, 'parent', 'code'));
		self::assertSame('utf8mb4', $this->fetchColumnsOf($dbal, 'child')['parent_code']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb4', $this->fetchColumnsOf($dbal, 'parent')['code']['CHARACTER_SET_NAME']);

		$constraints = $dbal->query(
			'SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS '
			. "WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'child'",
		);
		self::assertNotSame([], $constraints);
	}

	/**
	 * @dataProvider provide
	 */
	public function testForeignKeyChainWidensTransitively(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		// A 3-table FK chain where the WIDEST column is at the far (grandparent) end:
		//   a.b_code varchar(50) -> b.code varchar(100) -> c.code varchar(150)
		// The fixpoint must run multiple rounds: b.code is widened to 150 in round 1 (driven by c),
		// then a.b_code must be re-widened to 150 in round 2 (driven by the now-wider b). A single-pass
		// approach would stop a.b_code at 100 (b's original length), missing the transitive propagation.
		$db = 'outdated_collation_fk_chain';
		$this->setUpDatabase($dbal, $db);
		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `c` (
	`code` varchar(150) NOT NULL,
	PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `b` (
	`code` varchar(100) NOT NULL,
	PRIMARY KEY (`code`),
	CONSTRAINT `fk_b` FOREIGN KEY (`code`) REFERENCES `c` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `a` (
	`b_code` varchar(50) NOT NULL,
	KEY `k_b_code` (`b_code`),
	CONSTRAINT `fk_a` FOREIGN KEY (`b_code`) REFERENCES `b` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec("INSERT INTO `c` (`code`) VALUES ('x')");
		$dbal->exec("INSERT INTO `b` (`code`) VALUES ('x')");
		$dbal->exec("INSERT INTO `a` (`b_code`) VALUES ('x')");

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		// The key assertion: a.b_code widened to 150 transitively (c->b->a across fixpoint rounds).
		// A single-pass implementation would only reach 100 (b's original length before widening).
		self::assertStringContainsString('MODIFY `b_code` varchar(150)', $sql);
		// b.code and c.code both end at varchar(150).
		self::assertStringContainsString('MODIFY `code` varchar(150)', $sql);
		$this->runScript($dbal, $sql);

		// Round-trip clean.
		self::assertSame([], $auditor->analyse()->getViolations());

		// All three columns end at varchar(150), utf8mb4.
		self::assertSame('varchar(150)', $this->fetchColumnTypeOf($dbal, 'a', 'b_code'));
		self::assertSame('varchar(150)', $this->fetchColumnTypeOf($dbal, 'b', 'code'));
		self::assertSame('varchar(150)', $this->fetchColumnTypeOf($dbal, 'c', 'code'));
		self::assertSame('utf8mb4', $this->fetchColumnsOf($dbal, 'a')['b_code']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb4', $this->fetchColumnsOf($dbal, 'b')['code']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb4', $this->fetchColumnsOf($dbal, 'c')['code']['CHARACTER_SET_NAME']);

		// Both foreign keys are still present after migration.
		$constraintsA = $dbal->query(
			'SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS '
			. "WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'a'",
		);
		self::assertNotSame([], $constraintsA);
		$constraintsB = $dbal->query(
			'SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS '
			. "WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'b'",
		);
		self::assertNotSame([], $constraintsB);

		// An FK-respecting insert works across all three tables.
		$dbal->exec("INSERT INTO `c` (`code`) VALUES ('y')");
		$dbal->exec("INSERT INTO `b` (`code`) VALUES ('y')");
		$dbal->exec("INSERT INTO `a` (`b_code`) VALUES ('y')");
		$rows = $dbal->query('SELECT `b_code` FROM `a` ORDER BY `b_code`');
		self::assertSame('x', $rows[0]['b_code']);
		self::assertSame('y', $rows[1]['b_code']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testForeignKeyCycleWidensToMaxAndApplies(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		// A cyclic FK chain where every column is both a child and a parent:
		//   a.code varchar(50) -> b.code varchar(100) -> c.code varchar(150) -> a.code varchar(50)
		// The fixpoint must traverse the full cycle over multiple rounds: c widens b to 150 (round 1),
		// b widens a to 150 (round 2). a.code 50->150 and b.code 100->150 are only reachable by
		// propagating around the cycle — a single-pass or non-cyclic approach would miss them. The test
		// also guards fixpoint termination: a looping generate() would time out before reaching assertions.
		$db = 'outdated_collation_fk_cycle';
		$this->setUpDatabase($dbal, $db);
		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		// Create all three tables first; FK references require the target table to already exist.
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `a` (
	`code` varchar(50) NOT NULL,
	PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `b` (
	`code` varchar(100) NOT NULL,
	PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `c` (
	`code` varchar(150) NOT NULL,
	PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		// Close the cycle with three ALTERs (all tables must exist for the cross-references to resolve).
		$dbal->exec('ALTER TABLE `a` ADD CONSTRAINT `fk_a` FOREIGN KEY (`code`) REFERENCES `b` (`code`)');
		$dbal->exec('ALTER TABLE `b` ADD CONSTRAINT `fk_b` FOREIGN KEY (`code`) REFERENCES `c` (`code`)');
		$dbal->exec('ALTER TABLE `c` ADD CONSTRAINT `fk_c` FOREIGN KEY (`code`) REFERENCES `a` (`code`)');

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		// The key assertion: all three code columns are widened to the cycle's maximum (150).
		// A single-pass implementation would leave a.code at 100 (b's original length) and miss the
		// second propagation round that drives it to 150.
		self::assertSame(3, substr_count($sql, 'MODIFY `code` varchar(150)'));
		// All three FKs are dropped and re-added (cycle handled under foreign_key_checks off).
		self::assertSame(3, substr_count($sql, 'DROP FOREIGN KEY'));
		self::assertSame(3, substr_count($sql, 'ADD CONSTRAINT'));
		$this->runScript($dbal, $sql);

		// Round-trip clean: re-analyse reports no violations (idempotent).
		self::assertSame([], $auditor->analyse()->getViolations());

		// All three code columns are now varchar(150) utf8mb4.
		self::assertSame('varchar(150)', $this->fetchColumnTypeOf($dbal, 'a', 'code'));
		self::assertSame('varchar(150)', $this->fetchColumnTypeOf($dbal, 'b', 'code'));
		self::assertSame('varchar(150)', $this->fetchColumnTypeOf($dbal, 'c', 'code'));
		self::assertSame('utf8mb4', $this->fetchColumnsOf($dbal, 'a')['code']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb4', $this->fetchColumnsOf($dbal, 'b')['code']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb4', $this->fetchColumnsOf($dbal, 'c')['code']['CHARACTER_SET_NAME']);

		// All three foreign keys are still present after migration.
		$constraints = $dbal->query(
			'SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS '
			. 'WHERE CONSTRAINT_SCHEMA = DATABASE()',
		);
		self::assertCount(3, $constraints);
	}

	/**
	 * @dataProvider provide
	 */
	public function testForeignKeyMultipleChildrenWidenedToParent(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		// One parent is referenced by TWO children with different column widths:
		//   c1.p_code varchar(40) -> p.code varchar(120) <- c2.p_code varchar(200)
		// c1 must be widened to 120 (parent is larger); c2 must keep 200 (child is larger, no shrink).
		// This verifies that multi-child FK handling applies the max(child, parent) rule independently
		// per child and does not mix up or skip either child.
		$db = 'outdated_collation_fk_multichild';
		$this->setUpDatabase($dbal, $db);
		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `p` (
	`code` varchar(120) NOT NULL,
	PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `c1` (
	`p_code` varchar(40) NOT NULL,
	KEY `k_p_code` (`p_code`),
	CONSTRAINT `fk_c1` FOREIGN KEY (`p_code`) REFERENCES `p` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `c2` (
	`p_code` varchar(200) NOT NULL,
	KEY `k_p_code` (`p_code`),
	CONSTRAINT `fk_c2` FOREIGN KEY (`p_code`) REFERENCES `p` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec("INSERT INTO `p` (`code`) VALUES ('x')");
		$dbal->exec("INSERT INTO `c1` (`p_code`) VALUES ('x')");
		$dbal->exec("INSERT INTO `c2` (`p_code`) VALUES ('x')");

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		// c1 widened to parent's varchar(120); c2 kept at its own varchar(200); p stays at varchar(120).
		self::assertStringContainsString('MODIFY `p_code` varchar(120)', $sql);
		self::assertStringContainsString('MODIFY `p_code` varchar(200)', $sql);
		self::assertStringContainsString('MODIFY `code` varchar(120)', $sql);
		$this->runScript($dbal, $sql);

		// Round-trip clean.
		self::assertSame([], $auditor->analyse()->getViolations());

		// c1.p_code widened to 120; c2.p_code kept at 200; p.code stays at 120; all utf8mb4.
		self::assertSame('varchar(120)', $this->fetchColumnTypeOf($dbal, 'c1', 'p_code'));
		self::assertSame('varchar(200)', $this->fetchColumnTypeOf($dbal, 'c2', 'p_code'));
		self::assertSame('varchar(120)', $this->fetchColumnTypeOf($dbal, 'p', 'code'));
		self::assertSame('utf8mb4', $this->fetchColumnsOf($dbal, 'c1')['p_code']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb4', $this->fetchColumnsOf($dbal, 'c2')['p_code']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb4', $this->fetchColumnsOf($dbal, 'p')['code']['CHARACTER_SET_NAME']);

		// Both foreign keys are still present after migration.
		$constraintsC1 = $dbal->query(
			'SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS '
			. "WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'c1'",
		);
		self::assertNotSame([], $constraintsC1);
		$constraintsC2 = $dbal->query(
			'SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS '
			. "WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'c2'",
		);
		self::assertNotSame([], $constraintsC2);

		// FK-respecting inserts work for both children.
		$dbal->exec("INSERT INTO `p` (`code`) VALUES ('y')");
		$dbal->exec("INSERT INTO `c1` (`p_code`) VALUES ('y')");
		$dbal->exec("INSERT INTO `c2` (`p_code`) VALUES ('y')");
		$rows1 = $dbal->query('SELECT `p_code` FROM `c1` ORDER BY `p_code`');
		self::assertSame('x', $rows1[0]['p_code']);
		self::assertSame('y', $rows1[1]['p_code']);
		$rows2 = $dbal->query('SELECT `p_code` FROM `c2` ORDER BY `p_code`');
		self::assertSame('x', $rows2[0]['p_code']);
		self::assertSame('y', $rows2[1]['p_code']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testForeignKeyChildEndpointKeyLimitHoldsBack(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		// The CHILD endpoint's composite unique index overflows the 3072-byte key limit after conversion (it
		// fits as utf8mb3: 600 + 2100 = 2700 < 3072, but utf8mb4 would need 800 + 2800 = 3600 > 3072). The
		// child FK column is therefore unfixable, so the whole FK is held back: NEITHER endpoint is modified,
		// the FK is not dropped, and a "left unconverted" violation is recorded. The emitted SQL must apply.
		$db = 'outdated_collation_fk_child_keylimit';
		$this->setUpDatabase($dbal, $db);
		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `parent` (
	`code` varchar(200) NOT NULL,
	PRIMARY KEY (`code`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `child` (
	`id` int NOT NULL,
	`parent_code` varchar(200) NOT NULL,
	`other` varchar(700) NOT NULL,
	PRIMARY KEY (`id`),
	UNIQUE KEY `uq_parent_other` (`parent_code`, `other`),
	CONSTRAINT `fk_child_parent` FOREIGN KEY (`parent_code`) REFERENCES `parent` (`code`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec("INSERT INTO `parent` (`code`) VALUES ('cz')");
		$dbal->exec("INSERT INTO `child` (`id`, `parent_code`, `other`) VALUES (1, 'cz', 'x')");

		$report = (new Runner($dbal, [$auditor]))->generate();
		$sql = $report->getSql();
		// Neither FK endpoint is modified and the FK is left intact.
		self::assertStringNotContainsString('MODIFY `parent_code`', $sql);
		self::assertStringNotContainsString('MODIFY `code`', $sql);
		self::assertStringNotContainsString('DROP FOREIGN KEY', $sql);

		$unfixable = $report->getUnfixable();
		// The planner reports the FK as left unconverted as a unit.
		$fkViolation = $this->findViolationContaining($unfixable, 'fk_child_parent');
		self::assertNotNull($fkViolation);
		self::assertStringContainsString('left unconverted', $fkViolation);
		// The child's over-long index column is refused by the planner.
		$tooLong = $this->findColumnViolation($unfixable, 'parent_code');
		self::assertNotNull($tooLong);
		self::assertStringContainsString('3072-byte key length limit', $tooLong);

		// The generated SQL applies cleanly — no failing statement.
		$this->runScript($dbal, $sql);

		// Both endpoints stay utf8mb3 (held back); the FK is still present.
		self::assertSame('utf8mb3', $this->fetchColumnsOf($dbal, 'parent')['code']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb3', $this->fetchColumnsOf($dbal, 'child')['parent_code']['CHARACTER_SET_NAME']);
		$constraints = $dbal->query(
			'SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS '
			. "WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'child'",
		);
		self::assertNotSame([], $constraints);
	}

	/**
	 * @dataProvider provide
	 */
	public function testForeignKeyParentEndpointKeyLimitHoldsBack(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		// Symmetric to the child case: the PARENT key column is part of a composite unique index that overflows
		// the 3072-byte limit after conversion, while the child column alone would fit. The parent endpoint is
		// unfixable, so the whole FK is held back — both endpoints untouched, FK not dropped, no failing SQL.
		$db = 'outdated_collation_fk_parent_keylimit';
		$this->setUpDatabase($dbal, $db);
		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `parent` (
	`code` varchar(200) NOT NULL,
	`other` varchar(700) NOT NULL,
	PRIMARY KEY (`code`),
	UNIQUE KEY `uq_code_other` (`code`, `other`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `child` (
	`id` int NOT NULL,
	`parent_code` varchar(200) NOT NULL,
	PRIMARY KEY (`id`),
	KEY `k_parent_code` (`parent_code`),
	CONSTRAINT `fk_child_parent` FOREIGN KEY (`parent_code`) REFERENCES `parent` (`code`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec("INSERT INTO `parent` (`code`, `other`) VALUES ('cz', 'x')");
		$dbal->exec("INSERT INTO `child` (`id`, `parent_code`) VALUES (1, 'cz')");

		$report = (new Runner($dbal, [$auditor]))->generate();
		$sql = $report->getSql();
		// Neither FK endpoint is modified and the FK is left intact.
		self::assertStringNotContainsString('MODIFY `parent_code`', $sql);
		self::assertStringNotContainsString('MODIFY `code`', $sql);
		self::assertStringNotContainsString('DROP FOREIGN KEY', $sql);

		$unfixable = $report->getUnfixable();
		$fkViolation = $this->findViolationContaining($unfixable, 'fk_child_parent');
		self::assertNotNull($fkViolation);
		self::assertStringContainsString('left unconverted', $fkViolation);
		// The parent's over-long key column is refused by the planner.
		$tooLong = $this->findColumnViolation($unfixable, 'code');
		self::assertNotNull($tooLong);
		self::assertStringContainsString('3072-byte key length limit', $tooLong);

		$this->runScript($dbal, $sql);

		self::assertSame('utf8mb3', $this->fetchColumnsOf($dbal, 'parent')['code']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb3', $this->fetchColumnsOf($dbal, 'child')['parent_code']['CHARACTER_SET_NAME']);
		$constraints = $dbal->query(
			'SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS '
			. "WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'child'",
		);
		self::assertNotSame([], $constraints);
	}

	/**
	 * @dataProvider provide
	 */
	public function testForeignKeyWidenedChildOverflowsHeldBack(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		// The child fits its own composite unique index after conversion (500*4 + 200*4 = 2800 < 3072), but
		// WIDENING it to the parent's length (700) to satisfy the parent-driven length rule pushes the index
		// over the limit (700*4 + 200*4 = 3600 > 3072). The widened child is therefore unfixable, so the FK is
		// held back: neither endpoint converts NOR widens, the FK is not dropped, and the SQL applies cleanly.
		$db = 'outdated_collation_fk_widen_overflow';
		$this->setUpDatabase($dbal, $db);
		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `parent` (
	`code` varchar(700) NOT NULL,
	PRIMARY KEY (`code`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `child` (
	`id` int NOT NULL,
	`parent_code` varchar(500) NOT NULL,
	`other` varchar(200) NOT NULL,
	PRIMARY KEY (`id`),
	UNIQUE KEY `uq_parent_other` (`parent_code`, `other`),
	CONSTRAINT `fk_child_parent` FOREIGN KEY (`parent_code`) REFERENCES `parent` (`code`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec("INSERT INTO `parent` (`code`) VALUES ('cz')");
		$dbal->exec("INSERT INTO `child` (`id`, `parent_code`, `other`) VALUES (1, 'cz', 'x')");

		$report = (new Runner($dbal, [$auditor]))->generate();
		$sql = $report->getSql();
		// Neither endpoint converts nor widens; the FK is left intact.
		self::assertStringNotContainsString('MODIFY `parent_code`', $sql);
		self::assertStringNotContainsString('MODIFY `code`', $sql);
		self::assertStringNotContainsString('DROP FOREIGN KEY', $sql);

		$fkViolation = $this->findViolationContaining($report->getUnfixable(), 'fk_child_parent');
		self::assertNotNull($fkViolation);
		self::assertStringContainsString('left unconverted', $fkViolation);

		$this->runScript($dbal, $sql);

		// Both endpoints stay utf8mb3 and at their original lengths (no widening happened).
		self::assertSame('utf8mb3', $this->fetchColumnsOf($dbal, 'child')['parent_code']['CHARACTER_SET_NAME']);
		self::assertSame('varchar(500)', $this->fetchColumnTypeOf($dbal, 'child', 'parent_code'));
		self::assertSame('utf8mb3', $this->fetchColumnsOf($dbal, 'parent')['code']['CHARACTER_SET_NAME']);
		$constraints = $dbal->query(
			'SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS '
			. "WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'child'",
		);
		self::assertNotSame([], $constraints);
	}

	/**
	 * @dataProvider provide
	 */
	public function testExcludedTableNeedingConversionAbsentFromOutput(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		// An excluded table whose columns ALSO need conversion must be wholly absent from analyse() and
		// generate(): the by-table indexing never looks up its column/index rows.
		$db = 'outdated_collation_excluded_needs_conversion';
		$this->setUpDatabase($dbal, $db);

		$config = new OutdatedCollationConfig();
		$config->setExcludeTables((new TableExclude())->withPattern('^_'));
		$auditor = new OutdatedCollationMysqlAuditor($dbal, $config);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `_audit` (
	`id` int NOT NULL,
	`payload` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `article` (
	`id` int NOT NULL,
	`title` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);

		$violations = $auditor->analyse()->getViolations();
		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();

		// The included table converts.
		self::assertStringContainsString('MODIFY `title`', $sql);

		// The excluded table — though it equally needs conversion — appears nowhere.
		self::assertStringNotContainsString('`_audit`', $sql);
		self::assertStringNotContainsString('`payload`', $sql);
		self::assertNull($this->findColumnViolation($violations, 'payload'));
		self::assertNull($this->findViolationContaining($violations, '_audit'));

		$this->runScript($dbal, $sql);

		$columns = $this->fetchColumnsOf($dbal, '_audit');
		self::assertSame('utf8mb3', $columns['payload']['CHARACTER_SET_NAME']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testExcludedUnderscoreTablesSkipped(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'outdated_collation_underscore';
		$this->setUpDatabase($dbal, $db);

		$config = new OutdatedCollationConfig();
		$config->setExcludeTables((new TableExclude())->withPattern('^_'));
		$auditor = new OutdatedCollationMysqlAuditor($dbal, $config);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `_audit` (
	`id` int NOT NULL,
	`payload` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `article` (
	`id` int NOT NULL,
	`title` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		// The excluded `_audit` table is absent from the migration; the normal table is converted.
		self::assertStringNotContainsString('`_audit`', $sql);
		self::assertStringNotContainsString('`payload`', $sql);
		self::assertStringContainsString('MODIFY `title`', $sql);

		// The excluded table's column is not reported as a normal outdated column.
		self::assertNull($this->findColumnViolation($auditor->analyse()->getViolations(), 'payload'));

		$this->runScript($dbal, $sql);

		// The excluded table is left untouched (still utf8mb3).
		$columns = $this->fetchColumnsOf($dbal, '_audit');
		self::assertSame('utf8mb3', $columns['payload']['CHARACTER_SET_NAME']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testExcludedTablesAreNotIntrospected(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		// The heavy introspection is scoped in SQL to the non-excluded tables, so an excluded table is
		// never fetched. To prove the column data itself is never read (not merely filtered in PHP), the
		// excluded table carries a latin1 column which, under the default report() mode, WOULD surface as
		// an unfixable violation if it were introspected — it must produce no violation at all.
		$db = 'outdated_collation_excluded_not_introspected';
		$this->setUpDatabase($dbal, $db);

		$config = new OutdatedCollationConfig();
		$config->setExcludeTables((new TableExclude())->withPattern('^_'));
		$auditor = new OutdatedCollationMysqlAuditor($dbal, $config);

		// Excluded by the `_*` glob; its latin1 column would be reported unfixable if introspected.
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `_legacy` (
	`id` int NOT NULL,
	`name` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci
SQL,
		);
		// In scope; an ordinary utf8mb3 column needing conversion.
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `article` (
	`id` int NOT NULL,
	`title` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);

		$violations = $auditor->analyse()->getViolations();

		// The in-scope table is planned for conversion.
		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		self::assertStringContainsString('MODIFY `title`', $sql);

		// The excluded table is absent from the generated SQL.
		self::assertStringNotContainsString('`_legacy`', $sql);
		self::assertStringNotContainsString('MODIFY `name`', $sql);

		// Crucially: the excluded latin1 column produces NO violation of any kind — it was never
		// introspected (it would otherwise be reported unfixable under the default report() mode), nor was
		// the excluded table's default reported.
		self::assertNull($this->findColumnViolation($violations, 'name'));
		self::assertNull($this->findViolationContaining($violations, '_legacy'));

		$this->runScript($dbal, $sql);

		// The excluded table is left untouched (still latin1).
		$columns = $this->fetchColumnsOf($dbal, '_legacy');
		self::assertSame('latin1', $columns['name']['CHARACTER_SET_NAME']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testLatin1ReportedByDefault(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		// Default mode is report(): a legacy-charset column is NOT converted (the operator must first
		// audit the data and pick a mode), but a utf8mb3 column in the same database still converts.
		$db = 'outdated_collation_latin1';
		$this->setUpDatabase($dbal, $db);
		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `legacy` (
	`id` int NOT NULL,
	`name` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
	`note` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_czech_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec('SET NAMES latin1');
		$dbal->exec("INSERT INTO `legacy` (`id`, `name`, `note`) VALUES (1, 'caf\xE9', 'x')");
		$dbal->exec('SET NAMES utf8mb4');

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		// The legacy column is left untouched: no MODIFY of it and no VARBINARY two-step.
		self::assertStringNotContainsString('MODIFY `name`', $sql);
		self::assertStringNotContainsString('VARBINARY', $sql);
		self::assertStringNotContainsString('varbinary', strtolower($sql));
		// The utf8mb3 column in the same table still converts.
		self::assertStringContainsString('MODIFY `note`', $sql);

		// A violation names the legacy charset and asks the operator to choose a mode.
		$report = $this->findColumnViolation($auditor->analyse()->getViolations(), 'name');
		self::assertNotNull($report);
		self::assertStringContainsString("legacy charset 'latin1'", $report);
		self::assertStringContainsString('choose a conversion mode', $report);
		self::assertStringContainsString('latin1_encoding', $report);

		$this->runScript($dbal, $sql);

		// The legacy column stays latin1; the utf8mb3 column became utf8mb4.
		$columns = $this->fetchColumnsOf($dbal, 'legacy');
		self::assertSame('latin1', $columns['name']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb4', $columns['note']['CHARACTER_SET_NAME']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testMultibyteLegacyCharsetConvertedByDefault(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		// A multibyte legacy charset (gbk, MAXLEN = 2) holds genuine multibyte data that transcodes
		// losslessly to utf8mb4, so it converts straight under the DEFAULT config — no report() gate, no
		// assumeGenuine() needed. This proves the routing now distinguishes multibyte from single-byte.
		$db = 'outdated_collation_gbk';
		$this->setUpDatabase($dbal, $db);
		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `doc` (
	`id` int NOT NULL,
	`title` varchar(50) CHARACTER SET gbk COLLATE gbk_chinese_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=gbk COLLATE=gbk_chinese_ci
SQL,
		);
		// Insert a CJK value deterministically as its gbk byte sequence, independent of the connection charset.
		$dbal->exec("INSERT INTO `doc` (`id`, `title`) VALUES (1, CONVERT(_utf8mb4'中文' USING gbk))");

		$before = $auditor->analyse()->getViolations();
		self::assertNotSame([], $before);

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		// Straight conversion of both the column and the table default; no VARBINARY two-step.
		self::assertStringContainsString('MODIFY `title`', $sql);
		self::assertStringContainsString('DEFAULT CHARACTER SET = utf8mb4', $sql);
		self::assertStringNotContainsString('varbinary', strtolower($sql));

		$this->runScript($dbal, $sql);

		// Round-trip clean: re-analyse reports nothing.
		self::assertSame([], $auditor->analyse()->getViolations());

		// The CJK data reads back correctly as utf8mb4.
		$dbal->exec('SET NAMES utf8mb4');
		$row = $dbal->query('SELECT `title` FROM `doc` WHERE `id` = 1')[0];
		self::assertSame('中文', $row['title']);
		self::assertSame('utf8mb4', $this->fetchColumnsOf($dbal, 'doc')['title']['CHARACTER_SET_NAME']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testLatin1AssumeGenuineConverts(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'outdated_collation_latin1_genuine';
		$this->setUpDatabase($dbal, $db);

		$config = new OutdatedCollationConfig();
		$config->setLegacyCharsetConversion(LegacyCharsetConversion::assumeGenuine());
		$auditor = new OutdatedCollationMysqlAuditor($dbal, $config);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `legacy` (
	`id` int NOT NULL,
	`name` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		// Genuine latin1 data: an accented character stored over a latin1 connection.
		$dbal->exec('SET NAMES latin1');
		$dbal->exec("INSERT INTO `legacy` (`id`, `name`) VALUES (1, 'caf\xE9')");
		$dbal->exec('SET NAMES utf8mb4');

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		// Straight conversion: a single MODIFY, no VARBINARY two-step.
		self::assertStringContainsString('MODIFY `name`', $sql);
		self::assertStringNotContainsString('VARBINARY', $sql);
		self::assertStringNotContainsString('varbinary', strtolower($sql));

		$this->runScript($dbal, $sql);
		self::assertSame([], $auditor->analyse()->getViolations());

		// Genuine latin1 data round-trips to the correct utf8mb4 character.
		$row = $dbal->query('SELECT `name` FROM `legacy` WHERE `id` = 1')[0];
		self::assertSame('café', $row['name']);
		self::assertSame('utf8mb4', $this->fetchColumnsOf($dbal, 'legacy')['name']['CHARACTER_SET_NAME']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testLatin1AssumeDoubleEncodedTwoStep(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'outdated_collation_latin1_double';
		$this->setUpDatabase($dbal, $db);

		$config = new OutdatedCollationConfig();
		$config->setLegacyCharsetConversion(LegacyCharsetConversion::assumeDoubleEncoded());
		$auditor = new OutdatedCollationMysqlAuditor($dbal, $config);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `legacy` (
	`id` int NOT NULL,
	`name` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		// Double-encoded data: the UTF-8 bytes of 'é' (0xC3 0xA9) stored verbatim into a latin1 column
		// (over a latin1 connection each byte is taken literally), the classic mojibake state.
		$dbal->exec('SET NAMES latin1');
		$dbal->exec("INSERT INTO `legacy` (`id`, `name`) VALUES (1, 'caf\xC3\xA9')");
		$dbal->exec('SET NAMES utf8mb4');

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		// The two-step: reinterpret the bytes via VARBINARY, then read them as utf8mb4.
		self::assertStringContainsString('MODIFY `name` varbinary(100)', $sql);
		self::assertStringContainsString('MODIFY `name` varchar(100) CHARACTER SET utf8mb4', $sql);

		$this->runScript($dbal, $sql);
		self::assertSame([], $auditor->analyse()->getViolations());

		// The two-step recovered the genuine character from the double-encoded bytes.
		$row = $dbal->query('SELECT `name` FROM `legacy` WHERE `id` = 1')[0];
		self::assertSame('café', $row['name']);
		self::assertSame('utf8mb4', $this->fetchColumnsOf($dbal, 'legacy')['name']['CHARACTER_SET_NAME']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testLatin1TwoStepCarriesLockClause(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		// The VARBINARY prefix ALTER of the double-encoding two-step must carry the same configured LOCK
		// clause as the combined ALTER, so every emitted ALTER TABLE statement ends with it.
		$db = 'outdated_collation_latin1_double_lock';
		$this->setUpDatabase($dbal, $db);

		$config = new OutdatedCollationConfig();
		$config->setLegacyCharsetConversion(LegacyCharsetConversion::assumeDoubleEncoded());
		$auditor = new OutdatedCollationMysqlAuditor($dbal, $config);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `legacy` (
	`id` int NOT NULL,
	`name` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec('SET NAMES latin1');
		$dbal->exec("INSERT INTO `legacy` (`id`, `name`) VALUES (1, 'caf\xC3\xA9')");
		$dbal->exec('SET NAMES utf8mb4');

		$sql = (new Runner(
			$dbal,
			[$auditor],
			null,
			null,
			new BasicMigrationStrategy($dbal, AlterLock::shared()),
		))->generate()->getSql();
		// The VARBINARY prefix ALTER carries the lock clause too (single-line, comma-separated).
		self::assertStringContainsString('MODIFY `name` varbinary(100) NOT NULL, LOCK = SHARED', $sql);
		// Every emitted ALTER TABLE statement (prefix + combined) ends with the shared lock clause.
		foreach (explode(";\n", $sql) as $rawStatement) {
			$statement = rtrim(trim($rawStatement), ';');
			if (stripos($statement, 'ALTER TABLE') === 0) {
				self::assertStringEndsWith('LOCK = SHARED', $statement);
			}
		}

		$this->runScript($dbal, $sql);
		self::assertSame([], $auditor->analyse()->getViolations());

		$row = $dbal->query('SELECT `name` FROM `legacy` WHERE `id` = 1')[0];
		self::assertSame('café', $row['name']);
		self::assertSame('utf8mb4', $this->fetchColumnsOf($dbal, 'legacy')['name']['CHARACTER_SET_NAME']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testLatin1AssumeDoubleEncodedReportsEnum(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		// An ENUM column with a latin1 charset under assumeDoubleEncoded() cannot be byte-reinterpreted:
		// enum values are enumerated, not raw bytes, and binaryTypeFor() cannot map an enum type. It must
		// be reported UNFIXABLE and left out of the SQL entirely (no varbinary, no straight MODIFY).
		$db = 'outdated_collation_latin1_enum';
		$this->setUpDatabase($dbal, $db);

		$config = new OutdatedCollationConfig();
		$config->setLegacyCharsetConversion(LegacyCharsetConversion::assumeDoubleEncoded());
		$auditor = new OutdatedCollationMysqlAuditor($dbal, $config);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `legacy_enum` (
	`id` int NOT NULL,
	`status` enum('active','inactive','pending') CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec("INSERT INTO `legacy_enum` (`id`, `status`) VALUES (1, 'active'), (2, 'pending')");

		$violations = $auditor->analyse()->getViolations();
		self::assertNotSame([], $violations);

		// The enum column surfaces a violation naming the legacy charset.
		$report = $this->findColumnViolation($violations, 'status');
		self::assertNotNull($report);
		self::assertStringContainsString("legacy charset 'latin1'", $report);

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		// The enum column is NOT in the SQL: neither a varbinary two-step nor a straight MODIFY.
		self::assertStringNotContainsString('varbinary', strtolower($sql));
		self::assertStringNotContainsString('MODIFY `status`', $sql);

		// Whatever DDL remains is still replayable.
		$this->runScript($dbal, $sql);

		// The enum column stays latin1 (left unchanged).
		$columns = $this->fetchColumnsOf($dbal, 'legacy_enum');
		self::assertSame('latin1', $columns['status']['CHARACTER_SET_NAME']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testModernizeRoundTrip(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'outdated_collation_modernize';
		$this->setUpDatabase($dbal, $db);

		$config = new OutdatedCollationConfig();
		$config->setTargetPolicy(CollationTargetPolicy::modernize());
		$auditor = new OutdatedCollationMysqlAuditor($dbal, $config);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `doc` (
	`id` int NOT NULL,
	`title` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_czech_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec("INSERT INTO `doc` (`id`, `title`) VALUES (1, 'Žluťoučký')");

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		self::assertStringContainsString('MODIFY `title`', $sql);
		$this->runScript($dbal, $sql);

		self::assertSame([], $auditor->analyse()->getViolations());

		// The resolver's modernize preference list is
		// [utf8mb4_uca1400_as_ci, utf8mb4_unicode_520_ci, ...] for MariaDB and
		// [utf8mb4_0900_as_ci, ...] for MySQL; this MariaDB 11.4 build exposes no uca1400 collations, so
		// the resolver lands on utf8mb4_unicode_520_ci (the next available, matching the codebase's
		// established convention for this engine in testUniqueIndexDetectCollision).
		$expected = $engine === DatabaseEngine::mariadb()
			? 'utf8mb4_unicode_520_ci'
			: 'utf8mb4_0900_as_ci';
		$column = $dbal->query(
			'SELECT COLLATION_NAME FROM INFORMATION_SCHEMA.COLUMNS '
			. "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'doc' AND COLUMN_NAME = 'title'",
		)[0];
		self::assertSame($expected, $column['COLLATION_NAME']);

		$row = $dbal->query('SELECT `title` FROM `doc` WHERE `id` = 1')[0];
		self::assertSame('Žluťoučký', $row['title']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testSessionFkVarRestored(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'outdated_collation_session_fk';
		$this->setUpDatabase($dbal, $db);
		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `country` (
	`code` varchar(10) NOT NULL,
	PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `city` (
	`id` int NOT NULL,
	`country_code` varchar(10) NOT NULL,
	PRIMARY KEY (`id`),
	CONSTRAINT `fk_city_country` FOREIGN KEY (`country_code`) REFERENCES `country` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec("INSERT INTO `country` (`code`) VALUES ('cz')");
		$dbal->exec("INSERT INTO `city` (`id`, `country_code`) VALUES (1, 'cz')");

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		// Guard: the FK rebuild must actually emit the session wrap.
		self::assertStringContainsString('SET SESSION foreign_key_checks = 0', $sql);
		self::assertStringContainsString('foreign_key_checks = @ORISAI_DBAUDIT_FK', $sql);

		// Disable FK checks BEFORE applying the script; the script must restore this PRIOR value (0),
		// never force it back to 1.
		$dbal->exec('SET SESSION foreign_key_checks = 0');
		$this->runScript($dbal, $sql);

		$after = $dbal->query('SELECT @@SESSION.foreign_key_checks AS fk')[0];
		self::assertSame(0, (int) $after['fk']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testLatin1TableDefaultRespectsMode(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		// Default mode (report()): a table whose DEFAULT CHARSET is a legacy charset must NOT have its
		// table default converted — consistent with column-level report() behaviour. analyse() must still
		// surface the legacy table default as an advisory.
		$db = 'outdated_collation_latin1_tabledefault';
		$this->setUpDatabase($dbal, $db);
		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `legacy` (
	`id` int NOT NULL,
	`name` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci
SQL,
		);

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		// Neither the table default nor the latin1 column must appear in the generated SQL.
		self::assertStringNotContainsString('DEFAULT CHARACTER SET', $sql);
		self::assertStringNotContainsString('MODIFY `name`', $sql);

		// analyse() must surface the legacy table default as a violation.
		$violations = $auditor->analyse()->getViolations();
		$tableViolation = $this->findViolationContaining($violations, "legacy charset 'latin1'");
		self::assertNotNull($tableViolation);
		self::assertStringContainsString('choose a conversion mode', $tableViolation);
		self::assertStringContainsString('latin1_encoding', $tableViolation);

		// Whatever DDL remains (possibly empty) is still replayable.
		$this->runScript($dbal, $sql);

		// ---- assumeGenuine() mode: table default AND column must be converted ----
		$db2 = 'outdated_collation_latin1_tabledefault_genuine';
		$this->setUpDatabase($dbal, $db2);

		$config = new OutdatedCollationConfig();
		$config->setLegacyCharsetConversion(LegacyCharsetConversion::assumeGenuine());
		$auditor2 = new OutdatedCollationMysqlAuditor($dbal, $config);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `legacy` (
	`id` int NOT NULL,
	`name` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci
SQL,
		);
		$dbal->exec('SET NAMES latin1');
		$dbal->exec("INSERT INTO `legacy` (`id`, `name`) VALUES (1, 'caf\xE9')");
		$dbal->exec('SET NAMES utf8mb4');

		$sql2 = (new Runner($dbal, [$auditor2]))->generate()->getSql();
		// Both the table default and the column must be converted.
		self::assertStringContainsString('DEFAULT CHARACTER SET', $sql2);
		self::assertStringContainsString('MODIFY `name`', $sql2);

		$this->runScript($dbal, $sql2);
		// Round-trip: re-analyse must be clean.
		self::assertSame([], $auditor2->analyse()->getViolations());

		// Genuine latin1 data round-trips correctly.
		$row = $dbal->query('SELECT `name` FROM `legacy` WHERE `id` = 1')[0];
		self::assertSame('café', $row['name']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testExplicitReferenceInViewWarned(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'outdated_collation_ref_view';
		$this->setUpDatabase($dbal, $db);
		$dbal->exec(
			'CREATE TABLE `article` (`id` int NOT NULL, `title` varchar(50) NOT NULL)'
			. ' DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci',
		);
		// The view pins the OLD collation explicitly, which is exactly the kind of reference that errors once
		// the column is utf8mb4.
		$dbal->exec(
			'CREATE VIEW `article_sorted` AS SELECT `id`, `title` COLLATE utf8mb3_czech_ci AS `t`'
			. ' FROM `article`',
		);

		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		$migration = (new Runner($dbal, [$auditor]))->generate();
		// The clean SQL no longer embeds the charset-reference warning block — that now lives in the
		// migration's advisories (re-rendered as -- comment lines by the command, not here).
		self::assertStringNotContainsString('-- WARNING', $migration->getSql());
		self::assertStringNotContainsString('article_sorted` references', $migration->getSql());

		// generate()->getAdvisories() carries the standing advisory (grep targets) and the per-object hit.
		$genAdvisories = $migration->getAdvisories();
		$genAdvisory = $this->findAdvisoryContaining($genAdvisories, 'This migration changes charsets/collations');
		self::assertNotNull($genAdvisory);
		self::assertStringContainsString('utf8mb3_czech_ci', $genAdvisory);
		self::assertNotNull($this->findAdvisoryContaining($genAdvisories, 'VIEW article_sorted references'));

		$advisories = $auditor->analyse()->getAdvisories();
		$advisory = $this->findAdvisoryContaining($advisories, 'This migration changes charsets/collations');
		self::assertNotNull($advisory);
		self::assertStringContainsString('cannot inspect application code', $advisory);
		self::assertStringContainsString('utf8mb3_czech_ci', $advisory);

		$perObject = $this->findAdvisoryContaining($advisories, 'VIEW article_sorted references');
		self::assertNotNull($perObject);
		self::assertStringContainsString('utf8mb3_czech_ci', $perObject);
	}

	/**
	 * @dataProvider provide
	 */
	public function testAdvisoryWithoutExplicitReferences(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'outdated_collation_ref_none';
		$this->setUpDatabase($dbal, $db);
		$dbal->exec(
			'CREATE TABLE `plain` (`id` int NOT NULL, `name` varchar(50) NOT NULL)'
			. ' DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci',
		);

		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		// The clean SQL no longer carries the warning block.
		self::assertStringNotContainsString('-- WARNING', (new Runner($dbal, [$auditor]))->generate()->getSql());

		$advisories = $auditor->analyse()->getAdvisories();
		// The standing advisory with grep targets is always present once something is migrated.
		$advisory = $this->findAdvisoryContaining($advisories, 'This migration changes charsets/collations');
		self::assertNotNull($advisory);
		self::assertStringContainsString('utf8mb3_czech_ci', $advisory);
		// No stored object references the migrated names, so no per-object hit advisory is emitted.
		self::assertNull($this->findAdvisoryContaining($advisories, 'and may break after the migration'));
	}

	/**
	 * @dataProvider provide
	 */
	public function testNoWarningWhenNothingToMigrate(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'outdated_collation_ref_clean';
		$shortcuts = new MysqlShortcuts($dbal);
		$shortcuts->dropDatabaseIfExists($db);
		$dbal->exec(
			'CREATE DATABASE ' . $dbal->escapeIdentifier($db) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
		);
		$shortcuts->useDatabase($db);
		$dbal->exec(
			'CREATE TABLE `clean` (`id` int NOT NULL, `name` varchar(50) NOT NULL)'
			. ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
		);

		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		$migration = (new Runner($dbal, [$auditor]))->generate();
		self::assertSame('', $migration->getSql());
		self::assertSame([], $migration->getAdvisories());
		self::assertSame([], $auditor->analyse()->getAdvisories());
	}

	/**
	 * End-to-end through the Runner planner path: a plain utf8mb3 table converts and re-analyse finds no
	 * remaining fixable collation finding.
	 *
	 * @dataProvider provide
	 */
	public function testRunnerConvertsUtf8mb3Table(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'outdated_collation_runner_plain';
		$this->setUpDatabase($dbal, $db);
		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `article` (
	`id` int NOT NULL,
	`title` varchar(255) NOT NULL,
	`body` text NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec("INSERT INTO `article` (`id`, `title`, `body`) VALUES (1, 'Příliš', 'accented text')");

		$report = (new Runner($dbal, [$auditor]))->generate();
		self::assertNotSame('', $report->getSql());
		$this->runScript($dbal, $report->getSql());

		$after = (new Runner($dbal, [$auditor]))->analyse();
		foreach ($after->getErrors() as $violation) {
			self::assertStringStartsNotWith('outdated_collation.', $violation->getKey());
		}

		$columns = $this->fetchColumnsOf($dbal, 'article');
		self::assertSame('utf8mb4', $columns['title']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb4', $columns['body']['CHARACTER_SET_NAME']);
		self::assertSame('Příliš', $dbal->query('SELECT `title` FROM `article` WHERE `id` = 1')[0]['title']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testRunnerConvertsUniqueIndexColumn(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		// End-to-end through the Runner: an order-preserving unique-indexed column converts via a plain
		// MODIFY, the index is never dropped/re-added, and re-analyse is clean.
		$db = 'outdated_collation_runner_uq';
		$this->setUpDatabase($dbal, $db);
		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `tag` (
	`id` int NOT NULL,
	`name` varchar(100) NOT NULL,
	`scope` varchar(200) NOT NULL,
	UNIQUE KEY `uq_name_scope` (`name`, `scope`(20))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec(
			"INSERT INTO `tag` (`id`, `name`, `scope`) VALUES (1, 'red', 'global'), (2, 'blue', 'local')",
		);

		$report = (new Runner($dbal, [$auditor]))->generate();
		self::assertNotSame('', $report->getSql());
		self::assertStringContainsString('MODIFY `name`', $report->getSql());
		self::assertStringContainsString('MODIFY `scope`', $report->getSql());
		self::assertStringNotContainsString('DROP INDEX', $report->getSql());
		self::assertStringNotContainsString('ADD UNIQUE INDEX', $report->getSql());
		$this->runScript($dbal, $report->getSql());

		$after = (new Runner($dbal, [$auditor]))->analyse();
		foreach ($after->getErrors() as $violation) {
			self::assertStringStartsNotWith('outdated_collation.', $violation->getKey());
		}

		$columns = $this->fetchColumnsOf($dbal, 'tag');
		self::assertSame('utf8mb4', $columns['name']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb4', $columns['scope']['CHARACTER_SET_NAME']);

		$indexRows = $dbal->query(
			'SELECT NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS '
			. "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tag' AND INDEX_NAME = 'uq_name_scope'",
		);
		self::assertNotSame([], $indexRows);
		self::assertSame(0, (int) $indexRows[0]['NON_UNIQUE']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testRunnerLatin1DoubleEncodedTwoStep(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'outdated_collation_runner_latin1';
		$this->setUpDatabase($dbal, $db);

		$config = new OutdatedCollationConfig();
		$config->setLegacyCharsetConversion(LegacyCharsetConversion::assumeDoubleEncoded());
		$auditor = new OutdatedCollationMysqlAuditor($dbal, $config);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `legacy` (
	`id` int NOT NULL,
	`name` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec('SET NAMES latin1');
		$dbal->exec("INSERT INTO `legacy` (`id`, `name`) VALUES (1, 'caf\xC3\xA9')");
		$dbal->exec('SET NAMES utf8mb4');

		$report = (new Runner($dbal, [$auditor]))->generate();
		self::assertNotSame('', $report->getSql());
		// The two-step: the standalone VARBINARY prefix ALTER, then the utf8mb4 MODIFY in the combined ALTER.
		self::assertStringContainsString('MODIFY `name` varbinary(100)', $report->getSql());
		self::assertStringContainsString('MODIFY `name` varchar(100) CHARACTER SET utf8mb4', $report->getSql());
		$this->runScript($dbal, $report->getSql());

		$after = (new Runner($dbal, [$auditor]))->analyse();
		foreach ($after->getErrors() as $violation) {
			self::assertStringStartsNotWith('outdated_collation.', $violation->getKey());
		}

		$row = $dbal->query('SELECT `name` FROM `legacy` WHERE `id` = 1')[0];
		self::assertSame('café', $row['name']);
		self::assertSame('utf8mb4', $this->fetchColumnsOf($dbal, 'legacy')['name']['CHARACTER_SET_NAME']);
	}

	/**
	 * The two-step (latin1 assume-double-encoded) under a configured LOCK: the standalone VARBINARY prefix
	 * ALTER must carry the lock clause inline (`MODIFY ... , LOCK = SHARED`), and the whole migration must
	 * apply cleanly through the Runner on both engines.
	 *
	 * @dataProvider provide
	 */
	public function testRunnerLatin1DoubleEncodedTwoStepWithLock(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'outdated_collation_runner_latin1_lock';
		$this->setUpDatabase($dbal, $db);

		$config = new OutdatedCollationConfig();
		$config->setLegacyCharsetConversion(LegacyCharsetConversion::assumeDoubleEncoded());
		$auditor = new OutdatedCollationMysqlAuditor($dbal, $config);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `legacy` (
	`id` int NOT NULL,
	`name` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec('SET NAMES latin1');
		$dbal->exec("INSERT INTO `legacy` (`id`, `name`) VALUES (1, 'caf\xC3\xA9')");
		$dbal->exec('SET NAMES utf8mb4');

		$report = (new Runner(
			$dbal,
			[$auditor],
			null,
			null,
			new BasicMigrationStrategy($dbal, AlterLock::shared()),
		))->generate();
		self::assertNotSame('', $report->getSql());
		// The VARBINARY prefix ALTER carries the lock clause inline (single-line, comma-separated).
		self::assertStringContainsString('MODIFY `name` varbinary(100) NOT NULL, LOCK = SHARED', $report->getSql());
		self::assertStringContainsString('MODIFY `name` varchar(100) CHARACTER SET utf8mb4', $report->getSql());
		// Every emitted ALTER TABLE statement (prefix + combined) ends with the shared lock clause.
		foreach (explode(";\n", $report->getSql()) as $rawStatement) {
			$statement = rtrim(trim($rawStatement), ';');
			if (stripos($statement, 'ALTER TABLE') === 0) {
				self::assertStringEndsWith('LOCK = SHARED', $statement);
			}
		}

		$this->runScript($dbal, $report->getSql());

		$after = (new Runner($dbal, [$auditor]))->analyse();
		foreach ($after->getErrors() as $violation) {
			self::assertStringStartsNotWith('outdated_collation.', $violation->getKey());
		}

		// The two-step recovered the genuine character from the double-encoded bytes.
		$row = $dbal->query('SELECT `name` FROM `legacy` WHERE `id` = 1')[0];
		self::assertSame('café', $row['name']);
		self::assertSame('utf8mb4', $this->fetchColumnsOf($dbal, 'legacy')['name']['CHARACTER_SET_NAME']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testRunnerConvertsForeignKeyPair(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'outdated_collation_runner_fk';
		$this->setUpDatabase($dbal, $db);
		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `country` (
	`code` varchar(10) NOT NULL,
	PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `city` (
	`id` int NOT NULL,
	`country_code` varchar(10) NOT NULL,
	PRIMARY KEY (`id`),
	CONSTRAINT `fk_city_country` FOREIGN KEY (`country_code`) REFERENCES `country` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec("INSERT INTO `country` (`code`) VALUES ('cz')");
		$dbal->exec("INSERT INTO `city` (`id`, `country_code`) VALUES (1, 'cz')");

		$report = (new Runner($dbal, [$auditor]))->generate();
		self::assertNotSame('', $report->getSql());
		self::assertStringContainsString('DROP FOREIGN KEY `fk_city_country`', $report->getSql());
		self::assertStringContainsString('ADD CONSTRAINT `fk_city_country`', $report->getSql());
		$this->runScript($dbal, $report->getSql());

		$after = (new Runner($dbal, [$auditor]))->analyse();
		foreach ($after->getErrors() as $violation) {
			self::assertStringStartsNotWith('outdated_collation.', $violation->getKey());
		}

		self::assertSame('utf8mb4', $this->fetchColumnsOf($dbal, 'country')['code']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb4', $this->fetchColumnsOf($dbal, 'city')['country_code']['CHARACTER_SET_NAME']);

		// The foreign key still exists after the rebuild.
		$constraints = $dbal->query(
			'SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS '
			. "WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'city'",
		);
		self::assertNotSame([], $constraints);

		// An FK-respecting insert still works after the rebuild.
		$dbal->exec("INSERT INTO `country` (`code`) VALUES ('sk')");
		$dbal->exec("INSERT INTO `city` (`id`, `country_code`) VALUES (2, 'sk')");
		$rows = $dbal->query('SELECT `id`, `country_code` FROM `city` ORDER BY `id`');
		self::assertSame('sk', $rows[1]['country_code']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testRunnerChangesDatabaseDefault(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'outdated_collation_runner_dbdefault';
		$this->setUpDatabase($dbal, $db);
		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `note` (
	`id` int NOT NULL,
	`text` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);

		$report = (new Runner($dbal, [$auditor]))->generate();
		self::assertNotSame('', $report->getSql());
		self::assertStringContainsString('ALTER DATABASE', $report->getSql());
		$this->runScript($dbal, $report->getSql());

		$after = (new Runner($dbal, [$auditor]))->analyse();
		foreach ($after->getErrors() as $violation) {
			self::assertStringStartsNotWith('outdated_collation.', $violation->getKey());
		}

		$default = $dbal->query(
			'SELECT DEFAULT_CHARACTER_SET_NAME AS charset FROM INFORMATION_SCHEMA.SCHEMATA '
			. 'WHERE SCHEMA_NAME = DATABASE()',
		)[0];
		self::assertSame('utf8mb4', $default['charset']);
	}

	/**
	 * Ignoring the column whose post-conversion index prefix would exceed 767 bytes causes the
	 * planner to recompute the row-format bump from the surviving ColumnTargetChanges only: no
	 * ROW_FORMAT = DYNAMIC appears when the remaining column does not require it.
	 *
	 * @dataProvider provide
	 */
	public function testRowFormatBumpIgnoreAware(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		// Baseline: no ignores — both columns convert and the bump is emitted.
		$dbFull = 'outdated_collation_ignore_bump_full';
		$this->setUpDatabase($dbal, $dbFull);
		$auditorFull = new OutdatedCollationMysqlAuditor($dbal);

		// `big`: varchar(255) under an index; utf8mb4 conversion → 255×4=1020 > 767 bytes → forces DYNAMIC.
		// `small`: varchar(10) with no index; 10×4=40 bytes — well inside the COMPACT cap.
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `widget` (
	`id` int NOT NULL,
	`big` varchar(255) NOT NULL,
	`small` varchar(10) NOT NULL,
	KEY `k_big` (`big`)
) ENGINE=InnoDB ROW_FORMAT=COMPACT DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec("INSERT INTO `widget` (`id`, `big`, `small`) VALUES (1, 'hello', 'hi')");

		$fullSql = (new Runner($dbal, [$auditorFull]))->generate()->getSql();
		self::assertStringContainsString('ROW_FORMAT = DYNAMIC', $fullSql);
		self::assertStringContainsString('MODIFY `big`', $fullSql);
		self::assertStringContainsString('MODIFY `small`', $fullSql);
		$this->runScript($dbal, $fullSql);
		self::assertSame([], $auditorFull->analyse()->getViolations());

		// With the bump-driver ignored: the surviving column (`small`) fits COMPACT — no bump emitted.
		$dbPartial = 'outdated_collation_ignore_bump_partial';
		$this->setUpDatabase($dbal, $dbPartial);
		$auditorPartial = new OutdatedCollationMysqlAuditor($dbal);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `widget` (
	`id` int NOT NULL,
	`big` varchar(255) NOT NULL,
	`small` varchar(10) NOT NULL,
	KEY `k_big` (`big`)
) ENGINE=InnoDB ROW_FORMAT=COMPACT DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec("INSERT INTO `widget` (`id`, `big`, `small`) VALUES (1, 'hello', 'hi')");

		$ignores = new IgnoreList([
			new IgnoredError(null, null, 'widget', 'big', 'outdated_collation.column'),
		]);
		$partialSql = (new Runner($dbal, [$auditorPartial], $ignores))->generate()->getSql();

		// The bump driver is gone from survivors: no ROW_FORMAT = DYNAMIC.
		self::assertStringNotContainsString('ROW_FORMAT = DYNAMIC', $partialSql);
		// The surviving column still converts.
		self::assertStringContainsString('MODIFY `small`', $partialSql);
		// The ignored column is absent from the migration.
		self::assertStringNotContainsString('MODIFY `big`', $partialSql);

		$this->runScript($dbal, $partialSql);

		$cols = $this->fetchColumnsOf($dbal, 'widget');
		self::assertSame('utf8mb4', $cols['small']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb3', $cols['big']['CHARACTER_SET_NAME']);

		// `big` remains reported — the ignore only suppresses generation, not detection.
		$bigViolation = $this->findColumnViolation($auditorPartial->analyse()->getViolations(), 'big');
		self::assertNotNull($bigViolation);
	}

	/**
	 * Row-format bump is schema-driven: the generated SQL is byte-identical before and after
	 * clearing the table's rows (data must not influence the bump decision).
	 *
	 * @dataProvider provide
	 */
	public function testRowFormatBumpDeterministic(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'outdated_collation_bump_deterministic';
		$this->setUpDatabase($dbal, $db);

		// varchar(255) utf8mb3 + KEY: fits COMPACT before conversion (255×3=765≤767), exceeds after (255×4=1020>767).
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `slot` (
	`id` int NOT NULL,
	`token` varchar(255) NOT NULL,
	KEY `k_token` (`token`)
) ENGINE=InnoDB ROW_FORMAT=COMPACT DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec("INSERT INTO `slot` (`id`, `token`) VALUES (1, 'aaa'), (2, 'bbb'), (3, 'ccc')");

		$sqlWithData = (new Runner($dbal, [new OutdatedCollationMysqlAuditor($dbal)]))->generate()->getSql();
		// Precondition: the bump is present with rows in the table.
		self::assertStringContainsString('ROW_FORMAT = DYNAMIC', $sqlWithData);

		$dbal->exec('DELETE FROM `slot`');

		// Fresh runner forces a new SchemaContext read from the now-empty table; schema is unchanged.
		$sqlEmpty = (new Runner($dbal, [new OutdatedCollationMysqlAuditor($dbal)]))->generate()->getSql();
		self::assertSame($sqlWithData, $sqlEmpty);
	}

	/**
	 * Ignoring the PARENT endpoint's outdated_collation.column violation causes the planner's
	 * FK-endpoint consistency pass to hold back the CHILD endpoint too: neither column is
	 * modified and the FK is not rebuilt mismatched — no ERROR 3780 on either engine.
	 *
	 * @dataProvider provide
	 */
	public function testForeignKeyEndpointIgnoreHeldBack(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		// Baseline: no ignores — both endpoints convert and the FK is dropped+rebuilt cleanly.
		$dbFull = 'outdated_collation_ignore_fk_full';
		$this->setUpDatabase($dbal, $dbFull);
		$auditorFull = new OutdatedCollationMysqlAuditor($dbal);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `country` (
	`code` varchar(10) NOT NULL,
	PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `city` (
	`id` int NOT NULL,
	`country_code` varchar(10) NOT NULL,
	PRIMARY KEY (`id`),
	CONSTRAINT `fk_city_country` FOREIGN KEY (`country_code`) REFERENCES `country` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec("INSERT INTO `country` (`code`) VALUES ('cz')");
		$dbal->exec("INSERT INTO `city` (`id`, `country_code`) VALUES (1, 'cz')");

		$fullSql = (new Runner($dbal, [$auditorFull]))->generate()->getSql();
		// Both FK endpoints must convert.
		self::assertStringContainsString('MODIFY `code`', $fullSql);
		self::assertStringContainsString('MODIFY `country_code`', $fullSql);
		// The FK is dropped and re-added around the conversion.
		self::assertStringContainsString('DROP FOREIGN KEY `fk_city_country`', $fullSql);
		self::assertStringContainsString('ADD CONSTRAINT `fk_city_country`', $fullSql);
		// Apply cleanly on this engine — no ERROR 3780.
		$this->runScript($dbal, $fullSql);
		// Idempotent: re-analyse is clean after both endpoints converted.
		self::assertSame([], $auditorFull->analyse()->getViolations());

		// With the PARENT endpoint ignored: the planner holds back BOTH endpoints to prevent a
		// mismatched ADD CONSTRAINT that would cause ERROR 3780.
		$dbPartial = 'outdated_collation_ignore_fk_partial';
		$this->setUpDatabase($dbal, $dbPartial);
		$auditorPartial = new OutdatedCollationMysqlAuditor($dbal);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `country` (
	`code` varchar(10) NOT NULL,
	PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec(
			<<<'SQL'
CREATE TABLE `city` (
	`id` int NOT NULL,
	`country_code` varchar(10) NOT NULL,
	PRIMARY KEY (`id`),
	CONSTRAINT `fk_city_country` FOREIGN KEY (`country_code`) REFERENCES `country` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec("INSERT INTO `country` (`code`) VALUES ('cz')");
		$dbal->exec("INSERT INTO `city` (`id`, `country_code`) VALUES (1, 'cz')");

		$ignores = new IgnoreList([
			new IgnoredError(null, null, 'country', 'code', 'outdated_collation.column'),
		]);
		$partialReport = (new Runner($dbal, [$auditorPartial], $ignores))->generate();
		$partialSql = $partialReport->getSql();

		// Neither endpoint is converted — holding back the child prevents a mismatched ADD CONSTRAINT.
		self::assertStringNotContainsString('MODIFY `code`', $partialSql);
		self::assertStringNotContainsString('MODIFY `country_code`', $partialSql);
		// The FK must not be rebuilt: an ADD CONSTRAINT with mismatched endpoints would cause ERROR 3780.
		self::assertStringNotContainsString('ADD CONSTRAINT', $partialSql);

		// The planner records a change.foreign_key_inconsistent unfixable for the held-back FK.
		$fkViolation = $this->findViolationContaining($partialReport->getUnfixable(), 'fk_city_country');
		self::assertNotNull($fkViolation);
		self::assertStringContainsString('left unconverted', $fkViolation);
		$inconsistentKeyFound = false;
		foreach ($partialReport->getUnfixable() as $violation) {
			if ($violation->getKey() === 'change.foreign_key_inconsistent') {
				$inconsistentKeyFound = true;

				break;
			}
		}

		self::assertTrue($inconsistentKeyFound);

		// The generated SQL applies cleanly — no ERROR 3780.
		$this->runScript($dbal, $partialSql);

		// Both columns remain utf8mb3 (intentionally unconverted).
		self::assertSame('utf8mb3', $this->fetchColumnsOf($dbal, 'country')['code']['CHARACTER_SET_NAME']);
		self::assertSame('utf8mb3', $this->fetchColumnsOf($dbal, 'city')['country_code']['CHARACTER_SET_NAME']);

		// The child's outdated_collation violation is still reported on re-analyse (ignore suppresses
		// generation only, not detection).
		$childViolation = $this->findColumnViolation($auditorPartial->analyse()->getViolations(), 'country_code');
		self::assertNotNull($childViolation);
	}

	/**
	 * A column whose name contains a backtick must be properly escaped in the generated migration SQL
	 * (the backtick doubled inside back-quotes: `od``d`). Under naive wrapping the output would be
	 * syntactically broken and applying the migration would fail.
	 *
	 * @dataProvider provide
	 */
	public function testWeirdIdentifierEscapedInMigration(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'outdated_collation_weird_id';
		$this->setUpDatabase($dbal, $db);
		$auditor = new OutdatedCollationMysqlAuditor($dbal);

		// Column name od`d contains a literal backtick; in SQL the backtick is doubled inside back-quotes.
		$dbal->exec(
			'CREATE TABLE `weird` (`od``d` varchar(20) NOT NULL)'
			. ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci',
		);

		$before = $auditor->analyse()->getViolations();
		self::assertNotSame([], $before);

		$sql = (new Runner($dbal, [$auditor]))->generate()->getSql();
		// The generated SQL must double the backtick inside back-quotes, not produce a naively wrapped form.
		self::assertStringContainsString('`od``d`', $sql);

		// Applying must succeed on both engines (naive wrapping would be a syntax error).
		$this->runScript($dbal, $sql);

		// Idempotent: re-analyse reports nothing after conversion.
		self::assertSame([], $auditor->analyse()->getViolations());

		// The column is now utf8mb4 — escaping worked end-to-end.
		$columns = $this->fetchColumnsOf($dbal, 'weird');
		self::assertSame('utf8mb4', $columns['od`d']['CHARACTER_SET_NAME']);
	}

	/**
	 * A column that is BOTH outdated-charset AND nullable-with-no-nulls AND has a COMMENT produces one
	 * merged MODIFY (not two, not a conflict) with CHARACTER SET utf8mb4, NOT NULL, and the preserved
	 * COMMENT when both auditors run together through the Runner.
	 *
	 * @dataProvider provide
	 */
	public function testTwoAuditorColumnMerge(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$db = 'outdated_collation_two_auditor_merge';
		$this->setUpDatabase($dbal, $db);
		$collationAuditor = new OutdatedCollationMysqlAuditor($dbal);
		$nullableAuditor = new NullableWithNoNullsMysqlAuditor($dbal);

		$dbal->exec(
			<<<'SQL'
CREATE TABLE `item` (
	`id` int NOT NULL,
	`label` varchar(80) NULL COMMENT 'Preserved label'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
SQL,
		);
		$dbal->exec(
			"INSERT INTO `item` (`id`, `label`) VALUES (1, 'Žluťoučký'), (2, 'Příliš')",
		);

		// Before: both auditors flag `label`.
		self::assertNotSame([], $collationAuditor->analyse()->getViolations());
		self::assertNotSame([], $nullableAuditor->analyse()->getViolations());

		$report = (new Runner($dbal, [$collationAuditor, $nullableAuditor]))->generate();

		// No conflicts: the planner merges the two deltas for `label` instead of clashing.
		self::assertSame([], $report->getUnfixable());

		$sql = $report->getSql();

		// Exactly one MODIFY for `label`, not two.
		self::assertSame(1, substr_count($sql, 'MODIFY `label`'));

		// The merged MODIFY carries both changes and preserves the existing comment.
		self::assertStringContainsString('CHARACTER SET utf8mb4', $sql);
		self::assertStringContainsString('NOT NULL', $sql);
		self::assertStringContainsString("COMMENT 'Preserved label'", $sql);

		// The SQL applies cleanly on both engines.
		$this->runScript($dbal, $sql);

		// Idempotent: neither auditor flags `label` after the migration.
		self::assertSame([], $collationAuditor->analyse()->getViolations());
		self::assertSame([], $nullableAuditor->analyse()->getViolations());

		// The column is now utf8mb4, NOT NULL, and still carries the comment.
		$col = $dbal->query(
			'SELECT CHARACTER_SET_NAME, IS_NULLABLE, COLUMN_COMMENT FROM INFORMATION_SCHEMA.COLUMNS '
			. "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item' AND COLUMN_NAME = 'label'",
		)[0];
		self::assertSame('utf8mb4', (string) $col['CHARACTER_SET_NAME']);
		self::assertSame('NO', (string) $col['IS_NULLABLE']);
		self::assertSame('Preserved label', (string) $col['COLUMN_COMMENT']);
	}

	/**
	 * @param list<Violation> $violations
	 */
	private function findViolationContaining(array $violations, string $needle): ?string
	{
		foreach ($violations as $violation) {
			if (strpos($violation->getMessage(), $needle) !== false) {
				return $violation->getMessage();
			}
		}

		return null;
	}

	/**
	 * @param list<Violation> $violations
	 */
	private function findColumnViolation(array $violations, string $column): ?string
	{
		foreach ($violations as $violation) {
			if (strpos($violation->getMessage(), '[' . $column . ']') !== false) {
				return $violation->getMessage();
			}
		}

		return null;
	}

	/**
	 * @param list<Violation> $violations
	 */
	private function findViolationByKey(array $violations, string $key): ?Violation
	{
		foreach ($violations as $violation) {
			if ($violation->getKey() === $key) {
				return $violation;
			}
		}

		return null;
	}

	/**
	 * @param list<Advisory> $advisories
	 */
	private function findAdvisoryContaining(array $advisories, string $needle): ?string
	{
		foreach ($advisories as $advisory) {
			if (strpos($advisory->getMessage(), $needle) !== false) {
				return $advisory->getMessage();
			}
		}

		return null;
	}

	/**
	 * Returns the single-line ALTER TABLE statement (no LOCK clause in these tests) that targets the given
	 * table — i.e. the column/table-default migration, not the DROP/ADD FOREIGN KEY statements which carry
	 * their own action keyword after the backtick-quoted table name.
	 */
	private function statementForTable(string $sql, string $table): ?string
	{
		$needle = 'ALTER TABLE `' . $table . '` ';
		foreach (explode(";\n", $sql) as $rawStatement) {
			$statement = trim($rawStatement);
			if (strncmp($statement, $needle, strlen($needle)) !== 0) {
				continue;
			}

			if (strpos($statement, 'DROP FOREIGN KEY') !== false || strpos($statement, 'ADD CONSTRAINT') !== false) {
				continue;
			}

			return $statement . ';';
		}

		return null;
	}

	private function tableCollationOf(DbalAdapter $dbal, string $table): string
	{
		$rows = $dbal->query(
			'SELECT TABLE_COLLATION FROM INFORMATION_SCHEMA.TABLES '
			. 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $dbal->escapeString($table),
		);

		return (string) $rows[0]['TABLE_COLLATION'];
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function fetchColumnsOf(DbalAdapter $dbal, string $table): array
	{
		$rows = $dbal->query(
			'SELECT COLUMN_NAME, CHARACTER_SET_NAME FROM INFORMATION_SCHEMA.COLUMNS '
			. 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $dbal->escapeString($table),
		);

		$byName = [];
		foreach ($rows as $row) {
			$byName[(string) $row['COLUMN_NAME']] = $row;
		}

		return $byName;
	}

	private function fetchColumnTypeOf(DbalAdapter $dbal, string $table, string $column): string
	{
		$rows = $dbal->query(
			'SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS '
			. 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $dbal->escapeString($table)
			. ' AND COLUMN_NAME = ' . $dbal->escapeString($column),
		);

		return strtolower((string) $rows[0]['COLUMN_TYPE']);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function fetchColumns(DbalAdapter $dbal): array
	{
		$rows = $dbal->query(
			'SELECT COLUMN_NAME, CHARACTER_SET_NAME, COLUMN_DEFAULT, COLUMN_COMMENT, COLUMN_TYPE, '
			. 'GENERATION_EXPRESSION FROM INFORMATION_SCHEMA.COLUMNS '
			. "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'article'",
		);

		$byName = [];
		foreach ($rows as $row) {
			$byName[(string) $row['COLUMN_NAME']] = $row;
		}

		return $byName;
	}

	/**
	 * MySQL reports a literal string COLUMN_DEFAULT unquoted, MariaDB as a quoted SQL expression;
	 * normalise both to the underlying value.
	 *
	 * @param mixed $default
	 */
	private function normaliseDefault($default): string
	{
		$value = (string) $default;
		if (strlen($value) >= 2 && $value[0] === "'" && $value[strlen($value) - 1] === "'") {
			return substr($value, 1, -1);
		}

		return $value;
	}

	private function setUpDatabase(DbalAdapter $dbal, string $db): MysqlShortcuts
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$shortcuts->dropDatabaseIfExists($db);
		$dbal->exec(
			'CREATE DATABASE ' . $dbal->escapeIdentifier($db) . ' CHARACTER SET utf8mb3 COLLATE utf8mb3_czech_ci',
		);
		$shortcuts->useDatabase($db);

		return $shortcuts;
	}

	private function runScript(DbalAdapter $dbal, string $sql): void
	{
		foreach (explode(";\n", $sql) as $rawStatement) {
			$lines = [];
			foreach (explode("\n", $rawStatement) as $line) {
				if (strncmp($line, '--', 2) !== 0) {
					$lines[] = $line;
				}
			}

			$statement = rtrim(trim(implode("\n", $lines)), ';');
			if ($statement !== '') {
				// phpcs:ignore SlevomatCodingStandard.PHP.RequireExplicitAssertion.RequiredExplicitAssertion
				/** @var literal-string $literalStatement */
				$literalStatement = $statement;
				$dbal->exec($literalStatement);
			}
		}
	}

}

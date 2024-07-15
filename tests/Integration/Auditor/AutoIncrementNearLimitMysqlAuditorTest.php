<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Auditor;

use Generator;
use Orisai\DbAudit\Auditor\AutoIncrementNearLimitMysqlAuditor;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\DbProvider;
use Tests\Orisai\DbAudit\Helper\MysqlShortcuts;

final class AutoIncrementNearLimitMysqlAuditorTest extends TestCase
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
	public function test(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$auditor = new AutoIncrementNearLimitMysqlAuditor($dbal);

		$key = 'auto_increment_near_limit';

		$db = 'auto_increment_near_limit';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		self::assertEquals([], $auditor->analyse()->getViolations());

		// //////
		// TABLES
		// //////

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `no_id` (
	`tiny` TINYINT,
	`small` SMALLINT,
	`medium` MEDIUMINT,
	`regular` INT,
	`big` BIGINT
);
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `tiny` (
	`id` TINYINT AUTO_INCREMENT PRIMARY KEY
);
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `tiny_unsigned` (
	`id` TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
);
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `small` (
	`id` SMALLINT AUTO_INCREMENT PRIMARY KEY
);
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `small_unsigned` (
	`id` SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
);
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `medium` (
	`id` MEDIUMINT AUTO_INCREMENT PRIMARY KEY
);
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `medium_unsigned` (
	`id` MEDIUMINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
);
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `regular` (
	`id` INT AUTO_INCREMENT PRIMARY KEY
);
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `regular_unsigned` (
	`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
);
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `big` (
	`id` BIGINT AUTO_INCREMENT PRIMARY KEY
);
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `big_unsigned` (
	`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
);
SQL,
		);

		// //////
		// Values below threshold
		// //////

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO no_id (tiny, small, medium, regular, big)
VALUES (127, 32767, 8388607, 2147483647, 9223372036854775807);
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO tiny (id)
VALUES (113);
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO tiny_unsigned (id)
VALUES (228);
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO small (id)
VALUES (29489);
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO small_unsigned (id)
VALUES (58980);
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO medium (id)
VALUES (7549741);
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO medium_unsigned (id)
VALUES (15099484);
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO regular (id)
VALUES (1932734207);
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO regular_unsigned (id)
VALUES (3865468417);
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO big (id)
VALUES (8301030221483279797);
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO big_unsigned (id)
VALUES (16602060442966559597);
SQL,
		);

		self::assertEquals([], $auditor->analyse()->getViolations());

		// //////
		// Values above threshold
		// //////

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO tiny (id)
VALUES (114);
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO tiny_unsigned (id)
VALUES (229);
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO small (id)
VALUES (29490);
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO small_unsigned (id)
VALUES (58981);
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO medium (id)
VALUES (7549742);
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO medium_unsigned (id)
VALUES (15099485);
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO regular (id)
VALUES (1932734208);
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO regular_unsigned (id)
VALUES (3865468418);
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO big (id)
VALUES (8301030221483279798);
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO big_unsigned (id)
VALUES (16602060442966559598);
SQL,
		);

		$dbal->exec('ANALYZE TABLE tiny;');
		$dbal->exec('ANALYZE TABLE tiny_unsigned;');
		$dbal->exec('ANALYZE TABLE small;');
		$dbal->exec('ANALYZE TABLE small_unsigned;');
		$dbal->exec('ANALYZE TABLE medium;');
		$dbal->exec('ANALYZE TABLE medium_unsigned;');
		$dbal->exec('ANALYZE TABLE regular;');
		$dbal->exec('ANALYZE TABLE regular_unsigned;');
		$dbal->exec('ANALYZE TABLE big;');
		$dbal->exec('ANALYZE TABLE big_unsigned;');
		$dbal->exec('FLUSH TABLES;');

		$mariadb = $engine->value === 'mariadb';

		// MariaDB keeps integer display widths in COLUMN_TYPE; MySQL 8 dropped them. The auditor echoes
		// COLUMN_TYPE into the violation, so the expected type string is engine-specific.
		// big_unsigned: MySQL's INFORMATION_SCHEMA.TABLES.AUTO_INCREMENT is a signed BIGINT that saturates at
		// 9223372036854775807, so the BIGINT UNSIGNED reads as 50% and is never flagged; MariaDB reports the
		// true value and flags it like the rest.
		$columns = [
			['big', 'bigint', 'bigint(20)'],
			['big_unsigned', null, 'bigint(20) unsigned'],
			['medium', 'mediumint', 'mediumint(9)'],
			['medium_unsigned', 'mediumint unsigned', 'mediumint(8) unsigned'],
			['regular', 'int', 'int(11)'],
			['regular_unsigned', 'int unsigned', 'int(10) unsigned'],
			['small', 'smallint', 'smallint(6)'],
			['small_unsigned', 'smallint unsigned', 'smallint(5) unsigned'],
			['tiny', 'tinyint', 'tinyint(4)'],
			['tiny_unsigned', 'tinyint unsigned', 'tinyint(3) unsigned'],
		];

		$expected = [];
		foreach ($columns as [$table, $mysqlType, $mariadbType]) {
			$type = $mariadb ? $mariadbType : $mysqlType;
			if ($type === null) {
				continue;
			}

			$expected[] = new Violation(
				$key,
				"Autoincrement is above threshold of 90% in [$table][id] (Column type: '$type')",
				(new ColumnViolationSource($db, null, $table, 'id'))
					->setColumnType($type),
				false,
				'Migrate the column to a wider integer type (e.g. BIGINT) before it overflows.',
			);
		}

		$result = $auditor->analyse()->getViolations();
		self::assertEquals($result, $auditor->analyse()->getViolations());
		self::assertEquals($expected, $result);

		$auditor->setPercentileThreshold(91);
		$result = $auditor->analyse()->getViolations();
		self::assertEquals($result, $auditor->analyse()->getViolations());
		self::assertSame([], $result);
	}

	/**
	 * @dataProvider provide
	 */
	public function testUnrecognizedTypeAndBigUnsigned(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$auditor = new AutoIncrementNearLimitMysqlAuditor($dbal);
		$key = 'auto_increment_near_limit';

		$db = 'auto_increment_unrecognized';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		// FLOAT permits AUTO_INCREMENT but is not one of the recognised integer types. With ELSE 1 the
		// percentage degenerates to AUTO_INCREMENT * 100 and the column is always flagged.
		$dbal->exec(
		/** @lang MySQL */
			'CREATE TABLE `floaty` (`id` FLOAT AUTO_INCREMENT PRIMARY KEY)',
		);
		$dbal->exec(
		/** @lang MySQL */
			'INSERT INTO `floaty` (`id`) VALUES (1), (2), (3), (4), (5)',
		);

		// BIGINT UNSIGNED auto_increment near its limit. MariaDB reports the true value; MySQL's
		// INFORMATION_SCHEMA.TABLES.AUTO_INCREMENT is a signed BIGINT that saturates at 9223372036854775807,
		// so the same column reads as 50% there and cannot be detected.
		$dbal->exec(
		/** @lang MySQL */
			'CREATE TABLE `big_unsigned` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY)',
		);
		$dbal->exec(
		/** @lang MySQL */
			'INSERT INTO `big_unsigned` (`id`) VALUES (18000000000000000000)',
		);

		$dbal->exec('ANALYZE TABLE floaty;');
		$dbal->exec('ANALYZE TABLE big_unsigned;');
		$dbal->exec('FLUSH TABLES;');

		$expected = [];
		if ($engine->value === 'mariadb') {
			$expected[] = new Violation(
				$key,
				"Autoincrement is above threshold of 90% in [big_unsigned][id] (Column type: 'bigint(20) unsigned')",
				(new ColumnViolationSource($db, null, 'big_unsigned', 'id'))
					->setColumnType('bigint(20) unsigned'),
				false,
				'Migrate the column to a wider integer type (e.g. BIGINT) before it overflows.',
			);
		}

		self::assertEquals($expected, $auditor->analyse()->getViolations());
	}

}

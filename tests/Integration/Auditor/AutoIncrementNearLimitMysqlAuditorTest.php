<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Auditor;

use Dibi\Connection as DibiConnection;
use Generator;
use Nextras\Dbal\Connection as NextrasConnection;
use Orisai\DbAudit\Auditor\AutoIncrementNearLimitMysqlAuditor;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Dbal\DibiAdapter;
use Orisai\DbAudit\Dbal\NextrasAdapter;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\MysqlConnectionConfig;
use Tests\Orisai\DbAudit\Helper\MysqlShortcuts;

final class AutoIncrementNearLimitMysqlAuditorTest extends TestCase
{

	public function provide(): Generator
	{
		$config = new MysqlConnectionConfig(
			'127.0.0.1',
			'root',
			'root',
			(int) '3306',
		);

		yield [
			new DibiAdapter(new DibiConnection($config->toDibi())),
		];

		yield [
			new NextrasAdapter(new NextrasConnection($config->toNextras())),
		];
	}

	/**
	 * @dataProvider provide
	 */
	public function test(DbalAdapter $dbal): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$auditor = new AutoIncrementNearLimitMysqlAuditor($dbal);

		$key = $auditor::getKey();
		self::assertSame('auto_increment_near_limit', $key);

		$db = 'auto_increment_near_limit';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		self::assertEquals([], $auditor->analyse());

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

		self::assertEquals([], $auditor->analyse());

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

		//TODO - fix
		//		- buď se musí spustit analyze pro force update
		//		- nebo select hodnoty přes max
		//		- nebo přes order by a limit
		//		- zkontrolujeme UPDATE_TIME tabulek a případně spustíme ANALYZE
		//		- pouze pro mysql, jiných vendorů se netýká
		//self::assertNull($dbal->query(' SELECT MAX(`id`) FROM `big`'));
		$dbal->exec('ANALYZE TABLE tiny;');
		$dbal->exec('ANALYZE TABLE tiny_unsigned;');
		$dbal->exec('ANALYZE TABLE small;');
		$dbal->exec('ANALYZE TABLE small_unsigned;');
		$dbal->exec('ANALYZE TABLE medium;');
		$dbal->exec('ANALYZE TABLE medium_unsigned;');
		$dbal->exec('ANALYZE TABLE regular;');
		$dbal->exec('ANALYZE TABLE regular_unsigned;');
		$dbal->exec('ANALYZE TABLE big;');
		//TODO - nefunguje, nepřegeneruje se a nevytvoří se violation
		$dbal->exec('ANALYZE TABLE big_unsigned;');
		$dbal->exec('FLUSH TABLES;');

		$result = $auditor->analyse();
		self::assertEquals($result, $auditor->analyse());
		self::assertEquals([
			new Violation(
				$key,
				"Autoincrement is above threshold of 90% in [big][id] (Column type: 'bigint')",
				(new ColumnViolationSource($db, null, 'big', 'id'))
					->setColumnType('bigint'),
			),
			new Violation(
				$key,
				"Autoincrement is above threshold of 90% in [medium][id] (Column type: 'mediumint')",
				(new ColumnViolationSource($db, null, 'medium', 'id'))
					->setColumnType('mediumint'),
			),
			new Violation(
				$key,
				"Autoincrement is above threshold of 90% in [medium_unsigned][id] (Column type: 'mediumint unsigned')",
				(new ColumnViolationSource($db, null, 'medium_unsigned', 'id'))
					->setColumnType('mediumint unsigned'),
			),
			new Violation(
				$key,
				"Autoincrement is above threshold of 90% in [regular][id] (Column type: 'int')",
				(new ColumnViolationSource($db, null, 'regular', 'id'))
					->setColumnType('int'),
			),
			new Violation(
				$key,
				"Autoincrement is above threshold of 90% in [regular_unsigned][id] (Column type: 'int unsigned')",
				(new ColumnViolationSource($db, null, 'regular_unsigned', 'id'))
					->setColumnType('int unsigned'),
			),
			new Violation(
				$key,
				"Autoincrement is above threshold of 90% in [small][id] (Column type: 'smallint')",
				(new ColumnViolationSource($db, null, 'small', 'id'))
					->setColumnType('smallint'),
			),
			new Violation(
				$key,
				"Autoincrement is above threshold of 90% in [small_unsigned][id] (Column type: 'smallint unsigned')",
				(new ColumnViolationSource($db, null, 'small_unsigned', 'id'))
					->setColumnType('smallint unsigned'),
			),
			new Violation(
				$key,
				"Autoincrement is above threshold of 90% in [tiny][id] (Column type: 'tinyint')",
				(new ColumnViolationSource($db, null, 'tiny', 'id'))
					->setColumnType('tinyint'),
			),
			new Violation(
				$key,
				"Autoincrement is above threshold of 90% in [tiny_unsigned][id] (Column type: 'tinyint unsigned')",
				(new ColumnViolationSource($db, null, 'tiny_unsigned', 'id'))
					->setColumnType('tinyint unsigned'),
			),
		], $result);

		$auditor->setPercentileThreshold(91);
		$result = $auditor->analyse();
		self::assertEquals($result, $auditor->analyse());
		self::assertSame([], $result);
	}

}

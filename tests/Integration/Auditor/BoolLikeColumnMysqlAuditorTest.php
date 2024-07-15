<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Auditor;

use Dibi\Connection as DibiConnection;
use Generator;
use Nextras\Dbal\Connection as NextrasConnection;
use Orisai\DbAudit\Auditor\BoolLikeColumnMysqlAuditor;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Dbal\DibiAdapter;
use Orisai\DbAudit\Dbal\NextrasAdapter;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\MysqlConnectionConfig;
use Tests\Orisai\DbAudit\Helper\MysqlShortcuts;

final class BoolLikeColumnMysqlAuditorTest extends TestCase
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
		$auditor = new BoolLikeColumnMysqlAuditor($dbal);

		$key = $auditor::getKey();
		self::assertSame('bool_like_column', $key);

		$db = 'bool_like_column';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		self::assertEquals([], $auditor->analyse());

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `empty_table` (
	`tinyint` tinyint NOT NULL,
	`tinyint_null` tinyint unsigned NULL,
	`int` int NOT NULL,
	`int_null` int unsigned NULL
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `out_of_range_columns` (
	`tinyint` tinyint unsigned NOT NULL,
	`tinyint_2` tinyint NULL,
	`int` int unsigned NOT NULL,
	`int_2` int NULL
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `out_of_range_columns` (`tinyint`, `tinyint_2`, `int`, `int_2`) VALUES
	(0, 0, 0, 0),
	(1, 1, 1, 1),
	(2, -1, 2, -1)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `no_checks` (
	`tinyint` tinyint unsigned NULL,
	`int` int(1) NULL
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `no_checks` (`tinyint`, `int`) VALUES
	(1, 1),
	(0, 0),
	(null, null)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `checks` (
	`tinyint` tinyint unsigned NULL,
	`tinyint_2` tinyint unsigned NULL,
	`tinyint_3` tinyint unsigned NOT NULL,
	`tinyint_4` tinyint unsigned NOT NULL,
	`int` int(1) NOT NULL,
	CHECK ( `tinyint` IN (0, 1)),
	CHECK ( `tinyint_2` IN (1, 0)),
	CHECK(`tinyint_3`IN(0,1)),
	CHECK ( 0=0 AND `tinyint_4` IN (0, 1) AND 1=1),
	CHECK ( `int` IN (0, 1))
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `checks` (`tinyint`, `tinyint_2`, `tinyint_3`, `tinyint_4`, `int`) VALUES
	(1, 1, 1, 1, 1),
	(0, 0, 0, 0, 0),
	(null, null, 0, 0, 0)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `non_tiny_types` (
	`smallint` smallint unsigned NOT NULL,
	`mediumint` mediumint unsigned NOT NULL,
	`int` int unsigned NOT NULL,
	`bigint` bigint unsigned NOT NULL,
	CHECK ( `smallint` IN (0, 1)),
	CHECK ( `mediumint` IN (0, 1)),
	CHECK ( `int` IN (0, 1)),
	CHECK ( `bigint` IN (0, 1))
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `non_tiny_types` (`smallint`, `mediumint`, `int`, `bigint`) VALUES
	(1, 1, 1, 1),
	(0, 0, 0, 0)
SQL,
		);

		$report = $auditor->analyse();
		self::assertEquals($report, $auditor->analyse());
		self::assertEquals([
			new Violation(
				$key,
				"Column [checks][int] (Column type: 'int') contains only 0 and 1 but is not defined as tinyint.",
				(new ColumnViolationSource($db, null, 'checks', 'int'))
					->setColumnType('int'),
			),
			new Violation(
				$key,
				"Column [no_checks][int] (Column type: 'int') contains only 0 and 1 but is not defined as tinyint.",
				(new ColumnViolationSource($db, null, 'no_checks', 'int'))
					->setColumnType('int'),
			),
			new Violation(
				$key,
				"Column [no_checks][int] (Column type: 'int') contains only 0 and 1 but the table does not define CHECK ( `int` IN (0, 1)).",
				(new ColumnViolationSource($db, null, 'no_checks', 'int'))
					->setColumnType('int'),
			),
			new Violation(
				$key,
				"Column [no_checks][tinyint] (Column type: 'tinyint unsigned') "
				. 'contains only 0 and 1 but the table does not define CHECK ( `tinyint` IN (0, 1)).',
				(new ColumnViolationSource($db, null, 'no_checks', 'tinyint'))
					->setColumnType('tinyint unsigned'),
			),
			new Violation(
				$key,
				"Column [non_tiny_types][bigint] (Column type: 'bigint unsigned') contains only 0 and 1 but is not defined as tinyint.",
				(new ColumnViolationSource($db, null, 'non_tiny_types', 'bigint'))
					->setColumnType('bigint unsigned'),
			),
			new Violation(
				$key,
				"Column [non_tiny_types][int] (Column type: 'int unsigned') contains only 0 and 1 but is not defined as tinyint.",
				(new ColumnViolationSource($db, null, 'non_tiny_types', 'int'))
					->setColumnType('int unsigned'),
			),
			new Violation(
				$key,
				"Column [non_tiny_types][mediumint] (Column type: 'mediumint unsigned') contains only 0 and 1 but is not defined as tinyint.",
				(new ColumnViolationSource($db, null, 'non_tiny_types', 'mediumint'))
					->setColumnType('mediumint unsigned'),
			),
			new Violation(
				$key,
				"Column [non_tiny_types][smallint] (Column type: 'smallint unsigned') contains only 0 and 1 but is not defined as tinyint.",
				(new ColumnViolationSource($db, null, 'non_tiny_types', 'smallint'))
					->setColumnType('smallint unsigned'),
			),
		], $report);
	}

}

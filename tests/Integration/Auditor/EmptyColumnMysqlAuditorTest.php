<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Auditor;

use Dibi\Connection as DibiConnection;
use Generator;
use Nextras\Dbal\Connection as NextrasConnection;
use Orisai\DbAudit\Auditor\EmptyColumnMysqlAuditor;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Dbal\DibiAdapter;
use Orisai\DbAudit\Dbal\NextrasAdapter;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\MysqlConnectionConfig;
use Tests\Orisai\DbAudit\Helper\MysqlShortcuts;

final class EmptyColumnMysqlAuditorTest extends TestCase
{

	public function provide(): Generator
	{
		//TODO - přesunout provider do helper třídy? duplikují se napříč testy
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
		$auditor = new EmptyColumnMysqlAuditor($dbal);

		$key = $auditor::getKey();
		self::assertSame('empty_column', $key);

		$db = 'empty_column';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		self::assertEquals([], $auditor->analyse());

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `empty_table` (
	`a` tinyint(1) NOT NULL,
	`b` tinyint(1) NULL
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `full_table` (
	`a` tinyint(1) NOT NULL,
	`b` tinyint(1) NULL
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `full_table` (`a`, `b`) VALUES
(1, 1),
(1, null)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `empty_column_null` (
	`a` tinyint(1) NOT NULL,
	`b` tinyint(1) NULL
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `empty_column_null` (`a`, `b`) VALUES
(1, null)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `empty_column_text` (
	`a` text NOT NULL
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `empty_column_text` (`a`) VALUES
('')
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `háčky čárky` (
	`háčky čárky` tinyint(1) NULL
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `háčky čárky` (`háčky čárky`) VALUES
(null)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `all_string_types` (
	`varchar` varchar(255) NULL,
	`tinytext` varchar(255) NULL,
	`text` varchar(255) NULL,
	`mediumtext` varchar(255) NULL,
	`longtext` varchar(255) NULL
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `all_string_types` (`varchar`, `tinytext`, `text`, `mediumtext`, `longtext`) VALUES
	('', '', '', '', ''),
	(null, null, null, null, null)
SQL,
		);

		$report = $auditor->analyse();
		self::assertEquals([
			new Violation(
				$key,
				'Column [all_string_types][longtext] is empty.',
				new ColumnViolationSource($db, null, 'all_string_types', 'longtext'),
			),
			new Violation(
				$key,
				'Column [all_string_types][mediumtext] is empty.',
				new ColumnViolationSource($db, null, 'all_string_types', 'mediumtext'),
			),
			new Violation(
				$key,
				'Column [all_string_types][text] is empty.',
				new ColumnViolationSource($db, null, 'all_string_types', 'text'),
			),
			new Violation(
				$key,
				'Column [all_string_types][tinytext] is empty.',
				new ColumnViolationSource($db, null, 'all_string_types', 'tinytext'),
			),
			new Violation(
				$key,
				'Column [all_string_types][varchar] is empty.',
				new ColumnViolationSource($db, null, 'all_string_types', 'varchar'),
			),
			new Violation(
				$key,
				'Column [empty_column_null][b] is empty.',
				new ColumnViolationSource($db, null, 'empty_column_null', 'b'),
			),
			new Violation(
				$key,
				'Column [empty_column_text][a] is empty.',
				new ColumnViolationSource($db, null, 'empty_column_text', 'a'),
			),
			new Violation(
				$key,
				'Column [háčky čárky][háčky čárky] is empty.',
				new ColumnViolationSource($db, null, 'háčky čárky', 'háčky čárky'),
			),
		], $report);
		self::assertEquals($report, $auditor->analyse());
	}

}

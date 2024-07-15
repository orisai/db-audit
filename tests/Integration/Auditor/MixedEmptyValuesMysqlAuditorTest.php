<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Auditor;

use Dibi\Connection as DibiConnection;
use Generator;
use Nextras\Dbal\Connection as NextrasConnection;
use Orisai\DbAudit\Auditor\MixedEmptyValuesMysqlAuditor;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Dbal\DibiAdapter;
use Orisai\DbAudit\Dbal\NextrasAdapter;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\MysqlConnectionConfig;
use Tests\Orisai\DbAudit\Helper\MysqlShortcuts;

final class MixedEmptyValuesMysqlAuditorTest extends TestCase
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
		$auditor = new MixedEmptyValuesMysqlAuditor($dbal);

		$key = $auditor::getKey();
		self::assertSame('mixed_empty_values', $key);

		$db = 'mixed_empty_values';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		self::assertEquals([], $auditor->analyse());

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `empty_table` (
	`string` varchar(255) NOT NULL,
	`string_null` varchar(255) NULL
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `full_table` (
	`string` varchar(255) NOT NULL,
	`string_null` varchar(255) NULL
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `full_table` (`string`, `string_null`) VALUES
	('foo', 'bar'),
	('', ''),
	('', null)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `all_types` (
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
INSERT INTO `all_types` (`varchar`, `tinytext`, `text`, `mediumtext`, `longtext`) VALUES
	('', '', '', '', ''),
	(null, null, null, null, null)
SQL,
		);

		$report = $auditor->analyse();
		self::assertEquals($report, $auditor->analyse());
		self::assertEquals([
			new Violation(
				$key,
				'Column [all_types][longtext] contains mixed empty values.',
				new ColumnViolationSource($db, null, 'all_types', 'longtext'),
			),
			new Violation(
				$key,
				'Column [all_types][mediumtext] contains mixed empty values.',
				new ColumnViolationSource($db, null, 'all_types', 'mediumtext'),
			),
			new Violation(
				$key,
				'Column [all_types][text] contains mixed empty values.',
				new ColumnViolationSource($db, null, 'all_types', 'text'),
			),
			new Violation(
				$key,
				'Column [all_types][tinytext] contains mixed empty values.',
				new ColumnViolationSource($db, null, 'all_types', 'tinytext'),
			),
			new Violation(
				$key,
				'Column [all_types][varchar] contains mixed empty values.',
				new ColumnViolationSource($db, null, 'all_types', 'varchar'),
			),
			new Violation(
				$key,
				'Column [full_table][string_null] contains mixed empty values.',
				new ColumnViolationSource($db, null, 'full_table', 'string_null'),
			),
		], $report);
	}

}

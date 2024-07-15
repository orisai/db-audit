<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Auditor;

use Dibi\Connection as DibiConnection;
use Generator;
use Nextras\Dbal\Connection as NextrasConnection;
use Orisai\DbAudit\Auditor\NullableWithNoNullsMysqlAuditor;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Dbal\DibiAdapter;
use Orisai\DbAudit\Dbal\NextrasAdapter;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\MysqlConnectionConfig;
use Tests\Orisai\DbAudit\Helper\MysqlShortcuts;

final class NullableWithNoNullsAuditorTest extends TestCase
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
		$auditor = new NullableWithNoNullsMysqlAuditor($dbal);

		$key = $auditor::getKey();
		self::assertSame('nullable_with_no_nulls', $key);

		$db = 'nullable_with_no_nulls';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		self::assertEquals([], $auditor->analyse());

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `empty_table` (
	`a` int NOT NULL,
	`b` text NULL
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `full_table` (
	`a` int NOT NULL,
	`b` text NULL
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `full_table` (`a`, `b`) VALUES
(0, 'a'),
(0, null)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `no_nulls` (
	`a` int NULL,
	`b` text NULL
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `no_nulls` (`a`, `b`) VALUES
(0, ''),
(2, 'bar')
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `another_no_nulls` (
	`d` tinyint NULL,
	`c` varchar(255) NULL
)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `another_no_nulls` (`d`, `c`) VALUES
(0, ''),
(2, 'bar')
SQL,
		);

		$report = $auditor->analyse();
		self::assertEquals([
			new Violation(
				$key,
				'Column [another_no_nulls][c] is nullable but contains no nulls.',
				new ColumnViolationSource($db, null, 'another_no_nulls', 'c'),
			),
			new Violation(
				$key,
				'Column [another_no_nulls][d] is nullable but contains no nulls.',
				new ColumnViolationSource($db, null, 'another_no_nulls', 'd'),
			),
			new Violation(
				$key,
				'Column [no_nulls][a] is nullable but contains no nulls.',
				new ColumnViolationSource($db, null, 'no_nulls', 'a'),
			),
			new Violation(
				$key,
				'Column [no_nulls][b] is nullable but contains no nulls.',
				new ColumnViolationSource($db, null, 'no_nulls', 'b'),
			),
		], $report);
		self::assertEquals($report, $auditor->analyse());
	}

}

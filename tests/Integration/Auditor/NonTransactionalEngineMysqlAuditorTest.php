<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Auditor;

use Generator;
use Orisai\DbAudit\Auditor\NonTransactionalEngineMysqlAuditor;
use Orisai\DbAudit\Change\TableEngineChange;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Report\TableViolationSource;
use Orisai\DbAudit\Report\Violation;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\DbProvider;
use Tests\Orisai\DbAudit\Helper\MysqlShortcuts;

final class NonTransactionalEngineMysqlAuditorTest extends TestCase
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
		$auditor = new NonTransactionalEngineMysqlAuditor($dbal);
		$key = 'non_transactional_engine';

		$db = 'non_transactional_engine';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		self::assertEquals([], $auditor->analyse()->getViolations());

		$dbal->exec(/** @lang MySQL */ 'CREATE TABLE `myisam_t` (`a` int NOT NULL) ENGINE=MyISAM');
		$dbal->exec(/** @lang MySQL */ 'CREATE TABLE `memory_t` (`a` int NOT NULL) ENGINE=MEMORY');
		$dbal->exec(/** @lang MySQL */ 'CREATE TABLE `innodb_t` (`a` int NOT NULL) ENGINE=InnoDB');

		self::assertEquals([
			new Violation(
				$key,
				"Table [memory_t] uses non-transactional storage engine 'MEMORY'.",
				new TableViolationSource($db, null, 'memory_t'),
				true,
				'Convert the table to InnoDB for transactions, foreign keys and crash safety.',
				[new TableEngineChange($db, 'memory_t', 'InnoDB')],
			),
			new Violation(
				$key,
				"Table [myisam_t] uses non-transactional storage engine 'MyISAM'.",
				new TableViolationSource($db, null, 'myisam_t'),
				true,
				'Convert the table to InnoDB for transactions, foreign keys and crash safety.',
				[new TableEngineChange($db, 'myisam_t', 'InnoDB')],
			),
		], $auditor->analyse()->getViolations());
	}

}

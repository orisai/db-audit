<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Auditor;

use Generator;
use Orisai\DbAudit\Auditor\MissingPrimaryKeyMysqlAuditor;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Report\TableViolationSource;
use Orisai\DbAudit\Report\Violation;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\DbProvider;
use Tests\Orisai\DbAudit\Helper\MysqlShortcuts;

final class MissingPrimaryKeyMysqlAuditorTest extends TestCase
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
		$auditor = new MissingPrimaryKeyMysqlAuditor($dbal);
		$key = 'missing_primary_key';

		$db = 'missing_primary_key';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		self::assertEquals([], $auditor->analyse()->getViolations());

		$dbal->exec(/** @lang MySQL */ 'CREATE TABLE `no_pk` (`a` int NOT NULL)');
		$dbal->exec(/** @lang MySQL */ 'CREATE TABLE `with_pk` (`id` int NOT NULL, PRIMARY KEY (`id`))');
		$dbal->exec(/** @lang MySQL */ 'CREATE TABLE `unique_only` (`a` int NOT NULL, UNIQUE KEY `uq_a` (`a`))');

		self::assertEquals([
			new Violation(
				$key,
				'Table [no_pk] has no primary key.',
				new TableViolationSource($db, null, 'no_pk'),
				false,
				'Add a PRIMARY KEY (or a stable surrogate key).',
			),
			new Violation(
				$key,
				'Table [unique_only] has no primary key.',
				new TableViolationSource($db, null, 'unique_only'),
				false,
				'Add a PRIMARY KEY (or a stable surrogate key).',
			),
		], $auditor->analyse()->getViolations());
	}

	/**
	 * @dataProvider provide
	 */
	public function testReanalyseReflectsChange(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$auditor = new MissingPrimaryKeyMysqlAuditor($dbal);
		$key = 'missing_primary_key';

		$db = 'missing_primary_key_reanalyse';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		$dbal->exec(/** @lang MySQL */ 'CREATE TABLE `first_no_pk` (`a` int NOT NULL)');

		self::assertEquals([
			new Violation(
				$key,
				'Table [first_no_pk] has no primary key.',
				new TableViolationSource($db, null, 'first_no_pk'),
				false,
				'Add a PRIMARY KEY (or a stable surrogate key).',
			),
		], $auditor->analyse()->getViolations());

		$dbal->exec(/** @lang MySQL */ 'CREATE TABLE `second_no_pk` (`a` int NOT NULL)');

		self::assertEquals([
			new Violation(
				$key,
				'Table [first_no_pk] has no primary key.',
				new TableViolationSource($db, null, 'first_no_pk'),
				false,
				'Add a PRIMARY KEY (or a stable surrogate key).',
			),
			new Violation(
				$key,
				'Table [second_no_pk] has no primary key.',
				new TableViolationSource($db, null, 'second_no_pk'),
				false,
				'Add a PRIMARY KEY (or a stable surrogate key).',
			),
		], $auditor->analyse()->getViolations());
	}

}

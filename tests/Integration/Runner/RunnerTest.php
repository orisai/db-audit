<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Runner;

use Generator;
use Orisai\DbAudit\Analyser;
use Orisai\DbAudit\AnalyserCategory;
use Orisai\DbAudit\Auditor\EmptyTableMysqlAuditor;
use Orisai\DbAudit\Auditor\MissingPrimaryKeyMysqlAuditor;
use Orisai\DbAudit\Auditor\NonTransactionalEngineMysqlAuditor;
use Orisai\DbAudit\Auditor\NullableWithNoNullsMysqlAuditor;
use Orisai\DbAudit\Change\RawClauseChange;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Driver\SupportedDatabase;
use Orisai\DbAudit\Ignore\IgnoredError;
use Orisai\DbAudit\Ignore\IgnoreList;
use Orisai\DbAudit\Report\Advisory;
use Orisai\DbAudit\Report\AnalysisResult;
use Orisai\DbAudit\Report\TableViolationSource;
use Orisai\DbAudit\Report\Violation;
use Orisai\DbAudit\Runner\Runner;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\DbProvider;
use Tests\Orisai\DbAudit\Helper\MysqlShortcuts;
use function array_map;
use function sort;
use function substr_count;

final class RunnerTest extends TestCase
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
	public function testAnalyseByCategory(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$this->prepareOneEmptyTableWithoutPrimaryKey($dbal, 'runner_analyse');
		$runner = new Runner($dbal, [
			new MissingPrimaryKeyMysqlAuditor($dbal),
			new EmptyTableMysqlAuditor($dbal),
		]);

		self::assertSame(
			['empty_table', 'missing_primary_key'],
			$this->sortedKeys($runner->analyse()->getErrors()),
		);
		self::assertSame(
			['missing_primary_key'],
			$this->sortedKeys($runner->analyse(AnalyserCategory::structure())->getErrors()),
		);
		self::assertSame(
			['empty_table'],
			$this->sortedKeys($runner->analyse(AnalyserCategory::data())->getErrors()),
		);
	}

	/**
	 * @dataProvider provide
	 */
	public function testIgnoresAndUnmatched(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$this->prepareOneEmptyTableWithoutPrimaryKey($dbal, 'runner_ignore');

		$structureIgnores = new IgnoreList([
			new IgnoredError(null, null, null, null, 'missing_primary_key'),
			new IgnoredError(null, null, null, null, 'non_transactional_engine'), // never occurs -> stale
		]);
		$runner = new Runner($dbal, [new MissingPrimaryKeyMysqlAuditor($dbal)], $structureIgnores);

		$report = $runner->analyse(AnalyserCategory::structure());

		self::assertSame([], $report->getErrors());
		self::assertSame(1, $report->getIgnoredCount());
		self::assertCount(1, $report->getUnmatchedIgnores());
		self::assertSame('non_transactional_engine', $report->getUnmatchedIgnores()[0]->getKey());
		self::assertTrue($report->hasErrors()); // unmatched ignore counts as a failure
	}

	/**
	 * @dataProvider provide
	 */
	public function testUnsupportedAnalyserIsWarned(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$runner = new Runner($dbal, [$this->unsupportedAnalyser()]);

		$report = $runner->analyse();

		self::assertSame([], $report->getErrors());
		self::assertFalse($report->hasErrors());
		self::assertCount(1, $report->getWarnings());
		self::assertStringContainsString('does not support', $report->getWarnings()[0]->getMessage());
	}

	/**
	 * @dataProvider provide
	 */
	public function testGenerate(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$runner = new Runner($dbal, [$this->fixedGenerator()]);

		$report = $runner->generate();

		self::assertSame("ALTER TABLE `a` x;\nALTER TABLE `b` y;\n", $report->getSql());
		self::assertSame(2, $report->getGeneratedCount());
		self::assertTrue($report->hasUnfixable());
		self::assertCount(1, $report->getUnfixable());
		self::assertSame('demo.unfixable', $report->getUnfixable()[0]->getKey());
		self::assertCount(1, $report->getAdvisories());
		self::assertSame('search your code', $report->getAdvisories()[0]->getMessage());
	}

	/**
	 * @dataProvider provide
	 */
	public function testGenerateComposesChangeRequests(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$shortcuts->dropDatabaseIfExists('runner_generate');
		$shortcuts->createDatabase('runner_generate');
		$shortcuts->useDatabase('runner_generate');
		$dbal->exec(/** @lang MySQL */ 'CREATE TABLE `legacy` (`a` int NOT NULL) ENGINE=MyISAM');

		$report = (new Runner($dbal, [new NonTransactionalEngineMysqlAuditor($dbal)]))->generate();

		self::assertStringContainsString('ALTER TABLE `legacy` ENGINE=InnoDB;', $report->getSql());
		self::assertSame(1, $report->getGeneratedCount());
		self::assertFalse($report->hasUnfixable());
	}

	/**
	 * @dataProvider provide
	 */
	public function testGenerateMergesChangesFromDifferentAuditors(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$shortcuts->dropDatabaseIfExists('runner_merge');
		$shortcuts->createDatabase('runner_merge');
		$shortcuts->useDatabase('runner_merge');
		$dbal->exec(/** @lang MySQL */ 'CREATE TABLE `t` (`a` int NULL) ENGINE=MyISAM');
		$dbal->exec(/** @lang MySQL */ 'INSERT INTO `t` (`a`) VALUES (1), (2)');

		$report = (new Runner($dbal, [
			new NonTransactionalEngineMysqlAuditor($dbal), // structure
			new NullableWithNoNullsMysqlAuditor($dbal), // data
		]))->generate();

		// Engine (structure) and NOT NULL (data) changes for `t` merge into a single ALTER.
		self::assertSame(1, substr_count($report->getSql(), 'ALTER TABLE `t`'));
		self::assertStringContainsString('ENGINE=InnoDB', $report->getSql());
		self::assertStringContainsString('MODIFY `a`', $report->getSql());
		self::assertSame(2, $report->getGeneratedCount());

		$shortcuts->applyScript($report->getSql());

		$table = $dbal->query(
		/** @lang MySQL */
			"SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't'",
		);
		self::assertSame('InnoDB', (string) $table[0]['ENGINE']);

		$column = $dbal->query(
		/** @lang MySQL */
			"SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
			WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't' AND COLUMN_NAME = 'a'",
		);
		self::assertSame('NO', (string) $column[0]['IS_NULLABLE']);
	}

	/**
	 * @dataProvider provide
	 */
	public function testGenerateSkipsIgnoredChange(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$shortcuts->dropDatabaseIfExists('runner_generate_ignored');
		$shortcuts->createDatabase('runner_generate_ignored');
		$shortcuts->useDatabase('runner_generate_ignored');
		$dbal->exec(/** @lang MySQL */ 'CREATE TABLE `legacy` (`a` int NOT NULL) ENGINE=MyISAM');

		$ignores = new IgnoreList([new IgnoredError(null, null, null, null, 'non_transactional_engine')]);
		$report = (new Runner($dbal, [new NonTransactionalEngineMysqlAuditor($dbal)], $ignores))->generate();

		self::assertSame('', $report->getSql());
		self::assertSame(0, $report->getGeneratedCount());
	}

	private function prepareOneEmptyTableWithoutPrimaryKey(DbalAdapter $dbal, string $db): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);
		$dbal->exec(/** @lang MySQL */ 'CREATE TABLE `no_pk` (`a` int NOT NULL)');
	}

	/**
	 * @param list<Violation> $violations
	 * @return list<string>
	 */
	private function sortedKeys(array $violations): array
	{
		$keys = array_map(static fn (Violation $v): string => $v->getKey(), $violations);
		sort($keys);

		return $keys;
	}

	private function unsupportedAnalyser(): Analyser
	{
		return new class implements Analyser {

			public function getCategory(): AnalyserCategory
			{
				return AnalyserCategory::structure();
			}

			public function getSupportedDatabases(): array
			{
				return [
					new SupportedDatabase(DatabaseEngine::mysql(), 99),
					new SupportedDatabase(DatabaseEngine::mariadb(), 99),
				];
			}

			public function analyse(): AnalysisResult
			{
				return new AnalysisResult([]);
			}

		};
	}

	private function fixedGenerator(): Analyser
	{
		return new class implements Analyser {

			public function getCategory(): AnalyserCategory
			{
				return AnalyserCategory::structure();
			}

			public function getSupportedDatabases(): array
			{
				return [
					new SupportedDatabase(DatabaseEngine::mysql(), 8),
					new SupportedDatabase(DatabaseEngine::mariadb(), 10, 11),
				];
			}

			public function analyse(): AnalysisResult
			{
				return new AnalysisResult(
					[
						new Violation('demo.a', 'a', new TableViolationSource('db', null, 'a'), true, null, [
							new RawClauseChange('db', 'a', 'demo', 'x', 30),
						]),
						new Violation('demo.b', 'b', new TableViolationSource('db', null, 'b'), true, null, [
							new RawClauseChange('db', 'b', 'demo', 'y', 30),
						]),
						new Violation('demo.unfixable', 'left as-is', new TableViolationSource('db', null, 't')),
					],
					[new Advisory('search your code')],
				);
			}

		};
	}

}

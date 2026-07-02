<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Cmd;

use Generator;
use Orisai\DbAudit\Auditor\MissingPrimaryKeyMysqlAuditor;
use Orisai\DbAudit\Cmd\AnalyseCommand;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Ignore\Baseline;
use Orisai\DbAudit\Runner\Runner;
use Orisai\DbAudit\Schema\SchemaProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Orisai\DbAudit\Helper\DbProvider;
use Tests\Orisai\DbAudit\Helper\MysqlShortcuts;
use function file_get_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class AnalyseCommandTest extends TestCase
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
	public function testMissingCategoryFails(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$schema = new SchemaProvider($dbal);
		$tester = new CommandTester(
			new AnalyseCommand(new Runner($schema, [new MissingPrimaryKeyMysqlAuditor($schema)])),
		);

		$tester->execute([], ['decorated' => false]);

		self::assertSame(Command::FAILURE, $tester->getStatusCode());
		self::assertStringContainsString('Choose --category', $tester->getDisplay());
	}

	/**
	 * @dataProvider provide
	 */
	public function testInvalidCategory(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$schema = new SchemaProvider($dbal);
		$tester = new CommandTester(
			new AnalyseCommand(new Runner($schema, [new MissingPrimaryKeyMysqlAuditor($schema)])),
		);

		$tester->execute(['--category' => 'bogus'], ['decorated' => false]);

		self::assertSame(Command::FAILURE, $tester->getStatusCode());
		self::assertStringContainsString('Invalid --category', $tester->getDisplay());
	}

	/**
	 * @dataProvider provide
	 */
	public function testReportsErrorsAndFails(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$this->prepare($dbal, 'analyse_cmd', true);
		$schema = new SchemaProvider($dbal);
		$tester = new CommandTester(
			new AnalyseCommand(new Runner($schema, [new MissingPrimaryKeyMysqlAuditor($schema)])),
		);

		$tester->execute(['--category' => 'structure'], ['decorated' => false]);

		self::assertSame(Command::FAILURE, $tester->getStatusCode());
		$display = $tester->getDisplay();
		self::assertStringContainsString('has no primary key', $display);
		self::assertStringContainsString('identifier: missing_primary_key', $display);
		self::assertStringContainsString('Identifier', $display);
		self::assertStringContainsString('Found 1 error', $display);
		self::assertMatchesRegularExpression('~Time: \d+\.\d\ds~', $display);
		self::assertMatchesRegularExpression('~Memory: \d+\.\d MB~', $display);
	}

	/**
	 * @dataProvider provide
	 */
	public function testNoErrorsSucceeds(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$this->prepare($dbal, 'analyse_cmd_clean', false);
		$schema = new SchemaProvider($dbal);
		$tester = new CommandTester(
			new AnalyseCommand(new Runner($schema, [new MissingPrimaryKeyMysqlAuditor($schema)])),
		);

		$tester->execute(['--category' => 'structure'], ['decorated' => false]);

		self::assertSame(Command::SUCCESS, $tester->getStatusCode());
		self::assertStringContainsString('No errors', $tester->getDisplay());
	}

	/**
	 * `-b --category=structure` writes only the structure baseline; the data baseline file is left untouched.
	 * Re-running `--category=structure` (no `-b`) then subtracts those errors and the run succeeds.
	 *
	 * @dataProvider provide
	 */
	public function testGeneratesStructureBaselineAndThenSubtractsIt(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$this->prepare($dbal, 'analyse_cmd_baseline', true);
		$schema = new SchemaProvider($dbal);
		$structurePath = $this->tempPath();
		$dataPath = $this->tempPath();

		$writeTester = new CommandTester(
			new AnalyseCommand(
				new Runner($schema, [new MissingPrimaryKeyMysqlAuditor($schema)]),
				$structurePath,
				$dataPath,
			),
		);
		$writeTester->execute(
			['--category' => 'structure', '--generate-baseline' => true],
			['decorated' => false],
		);

		self::assertSame(Command::SUCCESS, $writeTester->getStatusCode());
		self::assertStringContainsString('Baseline written for structure: 1 entry', $writeTester->getDisplay());
		self::assertCount(1, Baseline::load($structurePath)->getErrors());
		self::assertSame('', (string) file_get_contents($dataPath));

		$subtractTester = new CommandTester(
			new AnalyseCommand(
				new Runner($schema, [new MissingPrimaryKeyMysqlAuditor($schema)]),
				$structurePath,
				$dataPath,
			),
		);
		$subtractTester->execute(['--category' => 'structure'], ['decorated' => false]);

		self::assertSame(Command::SUCCESS, $subtractTester->getStatusCode());
		$display = $subtractTester->getDisplay();
		self::assertStringContainsString('No errors', $display);
		self::assertStringContainsString('Baselined: 1', $display);

		unlink($structurePath);
		unlink($dataPath);
	}

	/**
	 * `-b --category=all` writes both baseline files, each containing only that category's own errors — never
	 * cross-contaminated.
	 *
	 * @dataProvider provide
	 */
	public function testGeneratesBaselineForAllCategoriesSeparately(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$this->prepare($dbal, 'analyse_cmd_baseline_all', true);
		$schema = new SchemaProvider($dbal);
		$structurePath = $this->tempPath();
		$dataPath = $this->tempPath();

		$tester = new CommandTester(
			new AnalyseCommand(
				new Runner($schema, [new MissingPrimaryKeyMysqlAuditor($schema)]),
				$structurePath,
				$dataPath,
			),
		);
		$tester->execute(['--category' => 'all', '--generate-baseline' => true], ['decorated' => false]);

		self::assertSame(Command::SUCCESS, $tester->getStatusCode());
		$display = $tester->getDisplay();
		self::assertStringContainsString('Baseline written for structure: 1 entry', $display);
		self::assertStringContainsString('Baseline written for data: 0 entries', $display);

		$structureErrors = Baseline::load($structurePath)->getErrors();
		self::assertCount(1, $structureErrors);
		self::assertSame('missing_primary_key', $structureErrors[0]->getKey());

		self::assertCount(0, Baseline::load($dataPath)->getErrors());

		unlink($structurePath);
		unlink($dataPath);
	}

	/**
	 * @dataProvider provide
	 */
	public function testGenerateBaselineFailsWithoutConfiguredPath(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$schema = new SchemaProvider($dbal);
		$tester = new CommandTester(
			new AnalyseCommand(new Runner($schema, [new MissingPrimaryKeyMysqlAuditor($schema)])),
		);

		$tester->execute(
			['--category' => 'structure', '--generate-baseline' => true],
			['decorated' => false],
		);

		self::assertSame(Command::FAILURE, $tester->getStatusCode());
		self::assertStringContainsString('No baseline path configured for structure', $tester->getDisplay());
	}

	private function prepare(DbalAdapter $dbal, string $db, bool $withMissingPrimaryKey): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		if ($withMissingPrimaryKey) {
			$dbal->exec(/** @lang MySQL */ 'CREATE TABLE `no_pk` (`a` int NOT NULL)');
		} else {
			$dbal->exec(/** @lang MySQL */ 'CREATE TABLE `has_pk` (`a` int NOT NULL, PRIMARY KEY (`a`))');
		}
	}

	private function tempPath(): string
	{
		$path = tempnam(sys_get_temp_dir(), 'dbaudit-cmd');
		if ($path === false) {
			self::fail('Could not create a temporary file.');
		}

		return $path;
	}

}

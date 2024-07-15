<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Cmd;

use Generator;
use Orisai\DbAudit\Auditor\MissingPrimaryKeyMysqlAuditor;
use Orisai\DbAudit\Cmd\AnalyseCommand;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Ignore\Baseline;
use Orisai\DbAudit\Runner\Runner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Orisai\DbAudit\Helper\DbProvider;
use Tests\Orisai\DbAudit\Helper\MysqlShortcuts;
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
	public function testReportsErrorsAndFails(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$this->prepare($dbal, 'analyse_cmd');
		$tester = new CommandTester(new AnalyseCommand(new Runner($dbal, [new MissingPrimaryKeyMysqlAuditor($dbal)])));

		$tester->execute([]);

		self::assertSame(Command::FAILURE, $tester->getStatusCode());
		$display = $tester->getDisplay();
		self::assertStringContainsString('has no primary key', $display);
		self::assertStringContainsString('identifier: missing_primary_key', $display);
		self::assertStringContainsString('Errors: 1', $display);
	}

	/**
	 * @dataProvider provide
	 */
	public function testGeneratesBaseline(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$this->prepare($dbal, 'analyse_cmd_baseline');
		$path = $this->tempPath();
		$tester = new CommandTester(new AnalyseCommand(new Runner($dbal, [new MissingPrimaryKeyMysqlAuditor($dbal)])));

		$tester->execute(['--category' => 'structure', '--generate-baseline' => $path]);

		self::assertSame(Command::SUCCESS, $tester->getStatusCode());
		self::assertStringContainsString('Baseline written: 1 entry', $tester->getDisplay());
		self::assertCount(1, Baseline::load($path)->getErrors());

		unlink($path);
	}

	/**
	 * @dataProvider provide
	 */
	public function testGenerateBaselineRequiresCategory(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$tester = new CommandTester(new AnalyseCommand(new Runner($dbal, [new MissingPrimaryKeyMysqlAuditor($dbal)])));

		$tester->execute(['--generate-baseline' => $this->tempPath()]);

		self::assertSame(Command::FAILURE, $tester->getStatusCode());
		self::assertStringContainsString('requires --category', $tester->getDisplay());
	}

	/**
	 * @dataProvider provide
	 */
	public function testInvalidCategory(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$tester = new CommandTester(new AnalyseCommand(new Runner($dbal, [new MissingPrimaryKeyMysqlAuditor($dbal)])));

		$tester->execute(['--category' => 'nope']);

		self::assertSame(Command::FAILURE, $tester->getStatusCode());
		self::assertStringContainsString('Invalid --category', $tester->getDisplay());
	}

	private function prepare(DbalAdapter $dbal, string $db): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);
		$dbal->exec(/** @lang MySQL */ 'CREATE TABLE `no_pk` (`a` int NOT NULL)');
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

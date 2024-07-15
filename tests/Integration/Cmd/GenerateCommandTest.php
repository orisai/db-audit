<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Cmd;

use Generator;
use Orisai\DbAudit\Analyser;
use Orisai\DbAudit\AnalyserCategory;
use Orisai\DbAudit\Change\RawClauseChange;
use Orisai\DbAudit\Cmd\GenerateCommand;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Driver\SupportedDatabase;
use Orisai\DbAudit\Report\Advisory;
use Orisai\DbAudit\Report\AnalysisResult;
use Orisai\DbAudit\Report\TableViolationSource;
use Orisai\DbAudit\Report\Violation;
use Orisai\DbAudit\Runner\Runner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Orisai\DbAudit\Helper\DbProvider;
use function file_get_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class GenerateCommandTest extends TestCase
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
	public function testReportsUnfixableFirstAndFails(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$tester = new CommandTester(new GenerateCommand(new Runner($dbal, [$this->generator(true)])));

		$tester->execute([]);

		self::assertSame(Command::FAILURE, $tester->getStatusCode());
		$display = $tester->getDisplay();
		self::assertStringContainsString('left as-is', $display);
		self::assertStringContainsString('Generated: 1   Unfixable: 1', $display);
		self::assertStringContainsString('advisory: scan your code', $display);
		self::assertStringContainsString('ALTER TABLE `a` x;', $display);
	}

	/**
	 * @dataProvider provide
	 */
	public function testWritesSqlToFile(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$path = $this->tempPath();
		$tester = new CommandTester(new GenerateCommand(new Runner($dbal, [$this->generator(true)])));

		$tester->execute(['--output' => $path]);

		self::assertStringContainsString('SQL written to', $tester->getDisplay());
		self::assertSame("ALTER TABLE `a` x;\n", file_get_contents($path));

		unlink($path);
	}

	/**
	 * @dataProvider provide
	 */
	public function testSucceedsWithoutUnfixable(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$tester = new CommandTester(new GenerateCommand(new Runner($dbal, [$this->generator(false)])));

		$tester->execute([]);

		self::assertSame(Command::SUCCESS, $tester->getStatusCode());
		self::assertStringContainsString('Unfixable: 0', $tester->getDisplay());
	}

	private function generator(bool $withUnfixable): Analyser
	{
		$unfixable = $withUnfixable
			? [new Violation('demo.unfixable', 'left as-is', new TableViolationSource('db', null, 't'))]
			: [];

		return new class ($unfixable) implements Analyser {

			/** @var list<Violation> */
			private array $unfixable;

			/**
			 * @param list<Violation> $unfixable
			 */
			public function __construct(array $unfixable)
			{
				$this->unfixable = $unfixable;
			}

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
				$violations = [
					new Violation('demo.a', 'a', new TableViolationSource('db', null, 'a'), true, null, [
						new RawClauseChange('db', 'a', 'demo', 'x', 30),
					]),
				];
				foreach ($this->unfixable as $violation) {
					$violations[] = $violation;
				}

				return new AnalysisResult($violations, [new Advisory('scan your code')]);
			}

		};
	}

	private function tempPath(): string
	{
		$path = tempnam(sys_get_temp_dir(), 'dbaudit-gen');
		if ($path === false) {
			self::fail('Could not create a temporary file.');
		}

		return $path;
	}

}

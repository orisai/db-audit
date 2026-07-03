<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Unit\Cmd;

use Orisai\DbAudit\Cmd\BaselineRemoveCommand;
use Orisai\DbAudit\Ignore\Baseline;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\TableViolationSource;
use Orisai\DbAudit\Report\Violation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use function file_get_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class BaselineRemoveCommandTest extends TestCase
{

	public function testRemoveByKey(): void
	{
		$structurePath = $this->baselineWithTwoEntries();
		$dataPath = $this->tempPath();
		$tester = new CommandTester(new BaselineRemoveCommand($structurePath, $dataPath));

		$tester->execute(['--category' => 'structure', '--key' => 'missing_primary_key'], ['decorated' => false]);

		self::assertSame(Command::SUCCESS, $tester->getStatusCode());
		self::assertStringContainsString('Removed 1 entry', $tester->getDisplay());
		$remaining = Baseline::load($structurePath)->getErrors();
		self::assertCount(1, $remaining);
		self::assertSame('outdated_collation.column', $remaining[0]->getKey());

		unlink($structurePath);
		unlink($dataPath);
	}

	public function testEditsOnlyTheConfiguredCategoryFile(): void
	{
		$structurePath = $this->baselineWithTwoEntries();
		$dataPath = $this->baselineWithTwoEntries();
		$dataContentsBefore = file_get_contents($dataPath);
		$tester = new CommandTester(new BaselineRemoveCommand($structurePath, $dataPath));

		$tester->execute(['--category' => 'structure', '--key' => 'missing_primary_key'], ['decorated' => false]);

		self::assertSame(Command::SUCCESS, $tester->getStatusCode());
		self::assertCount(1, Baseline::load($structurePath)->getErrors());
		self::assertCount(2, Baseline::load($dataPath)->getErrors());
		self::assertSame($dataContentsBefore, file_get_contents($dataPath));

		unlink($structurePath);
		unlink($dataPath);
	}

	public function testMissingCategoryFails(): void
	{
		$path = $this->baselineWithTwoEntries();
		$tester = new CommandTester(new BaselineRemoveCommand($path, $path));

		$tester->execute(['--key' => 'missing_primary_key'], ['decorated' => false]);

		self::assertSame(Command::FAILURE, $tester->getStatusCode());
		self::assertStringContainsString('Choose --category', $tester->getDisplay());

		unlink($path);
	}

	public function testAllCategoryIsRejected(): void
	{
		$path = $this->baselineWithTwoEntries();
		$tester = new CommandTester(new BaselineRemoveCommand($path, $path));

		$tester->execute(['--category' => 'all', '--key' => 'missing_primary_key'], ['decorated' => false]);

		self::assertSame(Command::FAILURE, $tester->getStatusCode());
		self::assertStringContainsString('Choose --category', $tester->getDisplay());

		unlink($path);
	}

	public function testUnconfiguredCategoryPathFails(): void
	{
		$tester = new CommandTester(new BaselineRemoveCommand());

		$tester->execute(['--category' => 'structure', '--key' => 'missing_primary_key'], ['decorated' => false]);

		self::assertSame(Command::FAILURE, $tester->getStatusCode());
		self::assertStringContainsString('No baseline path configured for structure', $tester->getDisplay());
	}

	public function testRequiresAtLeastOneFilter(): void
	{
		$path = $this->baselineWithTwoEntries();
		$tester = new CommandTester(new BaselineRemoveCommand($path, $path));

		$tester->execute(['--category' => 'structure'], ['decorated' => false]);

		self::assertSame(Command::FAILURE, $tester->getStatusCode());
		self::assertStringContainsString('at least one', $tester->getDisplay());

		unlink($path);
	}

	public function testRemoveByMessage(): void
	{
		$structurePath = $this->baselineWithTwoEntries();
		$dataPath = $this->tempPath();
		$tester = new CommandTester(new BaselineRemoveCommand($structurePath, $dataPath));

		$tester->execute(['--category' => 'structure', '--message' => 'no primary key'], ['decorated' => false]);

		self::assertSame(Command::SUCCESS, $tester->getStatusCode());
		self::assertStringContainsString('Removed 1 entry', $tester->getDisplay());
		$remaining = Baseline::load($structurePath)->getErrors();
		self::assertCount(1, $remaining);
		self::assertSame('outdated_collation.column', $remaining[0]->getKey());

		unlink($structurePath);
		unlink($dataPath);
	}

	private function baselineWithTwoEntries(): string
	{
		$path = $this->tempPath();

		Baseline::write($path, [
			new Violation(
				'outdated_collation.column',
				'Column [t][c] has an outdated charset/collation.',
				new ColumnViolationSource('db', null, 't', 'c'),
			),
			new Violation(
				'missing_primary_key',
				'Table [u] has no primary key.',
				new TableViolationSource('db', null, 'u'),
			),
		]);

		return $path;
	}

	private function tempPath(): string
	{
		$path = tempnam(sys_get_temp_dir(), 'dbaudit-baseline-cmd');
		if ($path === false) {
			self::fail('Could not create a temporary file.');
		}

		return $path;
	}

}

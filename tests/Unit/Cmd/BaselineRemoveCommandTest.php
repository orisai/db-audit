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
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class BaselineRemoveCommandTest extends TestCase
{

	public function testRemoveByKey(): void
	{
		$path = $this->baselineWithTwoEntries();
		$tester = new CommandTester(new BaselineRemoveCommand());

		$tester->execute(['path' => $path, '--key' => 'missing_primary_key']);

		self::assertSame(Command::SUCCESS, $tester->getStatusCode());
		self::assertStringContainsString('Removed 1 entry', $tester->getDisplay());
		$remaining = Baseline::load($path)->getErrors();
		self::assertCount(1, $remaining);
		self::assertSame('outdated_collation.column', $remaining[0]->getKey());

		unlink($path);
	}

	public function testRequiresAtLeastOneFilter(): void
	{
		$path = $this->baselineWithTwoEntries();
		$tester = new CommandTester(new BaselineRemoveCommand());

		$tester->execute(['path' => $path]);

		self::assertSame(Command::FAILURE, $tester->getStatusCode());
		self::assertStringContainsString('at least one', $tester->getDisplay());

		unlink($path);
	}

	public function testRejectsNonPositiveCount(): void
	{
		$path = $this->baselineWithTwoEntries();
		$tester = new CommandTester(new BaselineRemoveCommand());

		$tester->execute(['path' => $path, '--count' => '0']);

		self::assertSame(Command::FAILURE, $tester->getStatusCode());
		self::assertStringContainsString('positive integer', $tester->getDisplay());

		unlink($path);
	}

	private function baselineWithTwoEntries(): string
	{
		$path = tempnam(sys_get_temp_dir(), 'dbaudit-baseline-cmd');
		if ($path === false) {
			self::fail('Could not create a temporary file.');
		}

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

}

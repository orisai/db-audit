<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Cmd;

use Orisai\DbAudit\AnalyserCategory;
use Orisai\DbAudit\Runner\Runner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function count;
use function file_put_contents;
use function sprintf;

final class GenerateCommand extends Command
{

	/** @readonly */
	private Runner $runner;

	public function __construct(Runner $runner)
	{
		parent::__construct();
		$this->runner = $runner;
	}

	public static function getDefaultName(): string
	{
		return 'db-audit:generate';
	}

	public static function getDefaultDescription(): string
	{
		return 'Generate migration SQL for fixable findings';
	}

	protected function configure(): void
	{
		$this->addOption('category', null, InputOption::VALUE_REQUIRED, 'Limit to "structure" or "data"');
		$this->addOption(
			'output',
			null,
			InputOption::VALUE_REQUIRED,
			'Path to write the .sql file; if absent, the SQL is printed to stdout',
		);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$categoryOption = $input->getOption('category');
		$category = null;
		if ($categoryOption !== null) {
			$category = AnalyserCategory::tryFrom((string) $categoryOption);
			if ($category === null) {
				$output->writeln('<error>Invalid --category; use "structure" or "data".</error>');

				return self::FAILURE;
			}
		}

		$report = $this->runner->generate($category);
		$path = $input->getOption('output');

		// When the SQL goes to stdout, keep the report on stderr so the SQL can be piped cleanly.
		$reportOut = $path === null && $output instanceof ConsoleOutputInterface
			? $output->getErrorOutput()
			: $output;

		foreach ($report->getUnfixable() as $violation) {
			$reportOut->writeln($violation->getMessage());
			$reportOut->writeln('  identifier: ' . $violation->getKey());
			$reportOut->writeln('  source: ' . $violation->getSource()->toString());
			if ($violation->getHint() !== null) {
				$reportOut->writeln('  hint: ' . $violation->getHint());
			}

			$reportOut->writeln('');
		}

		$reportOut->writeln(sprintf(
			'Generated: %d   Unfixable: %d',
			$report->getGeneratedCount(),
			count($report->getUnfixable()),
		));

		foreach ($report->getAdvisories() as $advisory) {
			$reportOut->writeln('<comment>advisory: ' . $advisory->getMessage() . '</comment>');
		}

		foreach ($report->getWarnings() as $warning) {
			$reportOut->writeln('<comment>warning: ' . $warning->getMessage() . '</comment>');
		}

		if ($path !== null) {
			if (file_put_contents((string) $path, $report->getSql()) === false) {
				$reportOut->writeln('<error>Failed to write ' . $path . '</error>');

				return self::FAILURE;
			}

			$reportOut->writeln('SQL written to ' . $path);
		} else {
			$output->write($report->getSql());
		}

		return $report->hasUnfixable() ? self::FAILURE : self::SUCCESS;
	}

}

<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Cmd;

use Orisai\DbAudit\AnalyserCategory;
use Orisai\DbAudit\Ignore\Baseline;
use Orisai\DbAudit\Ignore\IgnoredError;
use Orisai\DbAudit\Runner\Runner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function count;
use function implode;
use function sprintf;

final class AnalyseCommand extends Command
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
		return 'db-audit:analyse';
	}

	public static function getDefaultDescription(): string
	{
		return 'Analyse the database and report violations';
	}

	protected function configure(): void
	{
		$this->addOption('category', null, InputOption::VALUE_REQUIRED, 'Limit to "structure" or "data"');
		$this->addOption(
			'generate-baseline',
			null,
			InputOption::VALUE_REQUIRED,
			'Write the current errors to a baseline file at the given path (requires --category)',
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

		$baselinePath = $input->getOption('generate-baseline');
		if ($baselinePath !== null) {
			if ($category === null) {
				$output->writeln('<error>--generate-baseline requires --category (structure or data).</error>');

				return self::FAILURE;
			}

			$errors = $this->runner->collectErrors($category);
			Baseline::write((string) $baselinePath, $errors);
			$output->writeln(
				sprintf('Baseline written: %d %s.', count($errors), count($errors) === 1 ? 'entry' : 'entries'),
			);

			return self::SUCCESS;
		}

		$report = $this->runner->analyse($category);

		foreach ($report->getErrors() as $violation) {
			$output->writeln($violation->getMessage());
			$output->writeln(sprintf(
				'  identifier: %s%s',
				$violation->getKey(),
				$violation->isFixable() ? '  (fixable)' : '',
			));
			$output->writeln('  source: ' . $violation->getSource()->toString());
			if ($violation->getHint() !== null) {
				$output->writeln('  hint: ' . $violation->getHint());
			}

			$output->writeln('');
		}

		foreach ($report->getWarnings() as $warning) {
			$output->writeln('<comment>warning: ' . $warning->getMessage() . '</comment>');
		}

		foreach ($report->getUnmatchedIgnores() as $ignore) {
			$output->writeln('<error>Ignored error never matched: ' . $this->describeIgnore($ignore) . '</error>');
		}

		$output->writeln(sprintf(
			'Errors: %d   Ignored: %d   Warnings: %d',
			count($report->getErrors()),
			$report->getIgnoredCount(),
			count($report->getWarnings()),
		));

		return $report->hasErrors() ? self::FAILURE : self::SUCCESS;
	}

	private function describeIgnore(IgnoredError $ignore): string
	{
		$parts = [];
		if ($ignore->getRawMessage() !== null) {
			$parts[] = 'rawMessage=' . $ignore->getRawMessage();
		}

		if ($ignore->getMessage() !== null) {
			$parts[] = 'message=' . $ignore->getMessage();
		}

		if ($ignore->getKey() !== null) {
			$parts[] = 'key=' . $ignore->getKey();
		}

		if ($ignore->getTable() !== null) {
			$parts[] = 'table=' . $ignore->getTable();
		}

		if ($ignore->getColumn() !== null) {
			$parts[] = 'column=' . $ignore->getColumn();
		}

		if ($ignore->getCount() !== null) {
			$parts[] = 'count=' . $ignore->getCount();
		}

		return implode(', ', $parts);
	}

}

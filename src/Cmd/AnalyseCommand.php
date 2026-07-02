<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Cmd;

use Orisai\DbAudit\AnalyserCategory;
use Orisai\DbAudit\Ignore\Baseline;
use Orisai\DbAudit\Ignore\IgnoredError;
use Orisai\DbAudit\Report\Violation;
use Orisai\DbAudit\Runner\AnalysisReport;
use Orisai\DbAudit\Runner\Runner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use function arsort;
use function count;
use function implode;
use function memory_get_peak_usage;
use function microtime;
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
		$this->addOption(
			'category',
			null,
			InputOption::VALUE_REQUIRED,
			'Choose "structure", "data" or "all" (required)',
		);
		$this->addOption(
			'generate-baseline',
			null,
			InputOption::VALUE_REQUIRED,
			'Write the current errors to a baseline file at the given path (requires a single --category)',
		);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);

		$categoryOption = $input->getOption('category');
		$categoryOption = $categoryOption !== null ? (string) $categoryOption : null;

		[$categoryOk, $category] = $this->resolveCategory($categoryOption);
		if (!$categoryOk) {
			if ($categoryOption === null || $categoryOption === '') {
				$io->error('Choose --category: structure, data or all.');
			} else {
				$io->error(sprintf(
					'Invalid --category "%s"; use "structure", "data" or "all".',
					$categoryOption,
				));
			}

			return self::FAILURE;
		}

		$baselinePath = $input->getOption('generate-baseline');
		if ($baselinePath !== null) {
			if ($category === null) {
				$io->error('--generate-baseline requires a single --category (structure or data), not "all".');

				return self::FAILURE;
			}

			$errors = $this->runner->collectErrors($category);
			Baseline::write((string) $baselinePath, $errors);
			$io->success(sprintf(
				'Baseline written: %d %s.',
				count($errors),
				count($errors) === 1 ? 'entry' : 'entries',
			));

			return self::SUCCESS;
		}

		$start = microtime(true);
		$report = $this->runner->analyse($category);
		$elapsed = microtime(true) - $start;
		$peakBytes = memory_get_peak_usage(true);

		$this->renderFindings($io, $report->getErrors());
		$this->renderSummaryTable($io, $report->getErrors());
		$this->renderStatus($io, $report);
		$this->renderWarningsAndUnmatched($io, $report);
		$this->renderFooter($io, $elapsed, $peakBytes);

		return $report->hasErrors() ? self::FAILURE : self::SUCCESS;
	}

	/**
	 * @return array{0: bool, 1: AnalyserCategory|null}
	 */
	private function resolveCategory(?string $option): array
	{
		if ($option === null || $option === '') {
			return [false, null];
		}

		if ($option === 'all') {
			return [true, null];
		}

		$category = AnalyserCategory::tryFrom($option);

		return [$category !== null, $category];
	}

	/**
	 * @param list<Violation> $errors
	 */
	private function renderFindings(SymfonyStyle $io, array $errors): void
	{
		foreach ($errors as $violation) {
			$io->writeln(sprintf('<fg=red>✕</> %s', $violation->getMessage()));

			$identifierLine = sprintf('<fg=gray>identifier: %s</>', $violation->getKey());
			if ($violation->isFixable()) {
				$identifierLine .= ' <fg=yellow>(fixable)</>';
			}

			$io->writeln('  ' . $identifierLine);
			$io->writeln(sprintf('  <fg=gray>source: %s</>', $violation->getSource()->toString()));

			if ($violation->getHint() !== null) {
				$io->writeln(sprintf('  <fg=gray>hint: %s</>', $violation->getHint()));
			}

			$io->newLine();
		}
	}

	/**
	 * @param list<Violation> $errors
	 */
	private function renderSummaryTable(SymfonyStyle $io, array $errors): void
	{
		if ($errors === []) {
			return;
		}

		$counts = [];
		foreach ($errors as $violation) {
			$key = $violation->getKey();
			$counts[$key] = ($counts[$key] ?? 0) + 1;
		}

		arsort($counts);

		$rows = [];
		foreach ($counts as $identifier => $count) {
			$rows[] = [$identifier, $count];
		}

		$io->table(['Identifier', 'Count'], $rows);
	}

	private function renderStatus(SymfonyStyle $io, AnalysisReport $report): void
	{
		$errorCount = count($report->getErrors());
		if ($report->hasErrors()) {
			$io->error(sprintf('Found %d error%s', $errorCount, $errorCount === 1 ? '' : 's'));
		} else {
			$io->success('No errors');
		}

		$warningCount = count($report->getWarnings());
		$io->writeln(sprintf(
			'Ignored: %d   Warnings: %d%s',
			$report->getIgnoredCount(),
			$warningCount,
			$warningCount > 0 ? ' ⚠️' : '',
		));
		$io->newLine();
	}

	private function renderWarningsAndUnmatched(SymfonyStyle $io, AnalysisReport $report): void
	{
		foreach ($report->getWarnings() as $warning) {
			$io->warning($warning->getMessage());
		}

		foreach ($report->getUnmatchedIgnores() as $ignore) {
			$io->error('Ignored error never matched: ' . $this->describeIgnore($ignore));
		}
	}

	private function renderFooter(SymfonyStyle $io, float $elapsed, int $peakBytes): void
	{
		$io->writeln(sprintf('⏱  Time: %.2fs   💾  Memory: %.1f MB', $elapsed, $peakBytes / 1_048_576));
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

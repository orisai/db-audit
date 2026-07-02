<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Cmd;

use Orisai\DbAudit\AnalyserCategory;
use Orisai\DbAudit\Ignore\Baseline;
use Orisai\DbAudit\Ignore\IgnoredError;
use Orisai\DbAudit\Report\Violation;
use Orisai\DbAudit\Report\Warning;
use Orisai\DbAudit\Runner\Runner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use function arsort;
use function count;
use function implode;
use function is_file;
use function memory_get_peak_usage;
use function microtime;
use function sprintf;

final class AnalyseCommand extends Command
{

	/** @readonly */
	private Runner $runner;

	/** @readonly */
	private ?string $structureBaselinePath;

	/** @readonly */
	private ?string $dataBaselinePath;

	public function __construct(
		Runner $runner,
		?string $structureBaselinePath = null,
		?string $dataBaselinePath = null
	)
	{
		parent::__construct();
		$this->runner = $runner;
		$this->structureBaselinePath = $structureBaselinePath;
		$this->dataBaselinePath = $dataBaselinePath;
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
			'b',
			InputOption::VALUE_NONE,
			'Write all current errors to the configured baseline(s) and succeed',
		);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);

		$categoryOption = $input->getOption('category');
		$categoryOption = $categoryOption !== null ? (string) $categoryOption : null;

		[$categoriesOk, $categories] = $this->resolveCategories($categoryOption);
		if (!$categoriesOk) {
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

		if ($input->getOption('generate-baseline') === true) {
			return $this->generateBaselines($io, $categories);
		}

		return $this->analyseAndReport($io, $categories);
	}

	/**
	 * @return array{0: bool, 1: list<AnalyserCategory>}
	 */
	private function resolveCategories(?string $option): array
	{
		if ($option === null || $option === '') {
			return [false, []];
		}

		if ($option === 'all') {
			return [true, [AnalyserCategory::structure(), AnalyserCategory::data()]];
		}

		$category = AnalyserCategory::tryFrom($option);
		if ($category === null) {
			return [false, []];
		}

		return [true, [$category]];
	}

	private function baselinePathFor(AnalyserCategory $category): ?string
	{
		return $category === AnalyserCategory::structure() ? $this->structureBaselinePath : $this->dataBaselinePath;
	}

	/**
	 * @param list<AnalyserCategory> $categories
	 */
	private function generateBaselines(SymfonyStyle $io, array $categories): int
	{
		// Validate every target path before writing any file, so `--category=all` with one path missing fails
		// without leaving a half-written pair of baselines.
		$targets = [];
		foreach ($categories as $category) {
			$path = $this->baselinePathFor($category);
			if ($path === null) {
				$io->error(sprintf('No baseline path configured for %s.', $category->value));

				return self::FAILURE;
			}

			$targets[] = [$category, $path];
		}

		foreach ($targets as [$category, $path]) {
			$errors = $this->runner->collectErrors($category);
			Baseline::write($path, $errors);
			$io->success(sprintf(
				'Baseline written for %s: %d %s.',
				$category->value,
				count($errors),
				count($errors) === 1 ? 'entry' : 'entries',
			));
		}

		return self::SUCCESS;
	}

	/**
	 * @param list<AnalyserCategory> $categories
	 */
	private function analyseAndReport(SymfonyStyle $io, array $categories): int
	{
		$start = microtime(true);

		$errors = [];
		$ignoredCount = 0;
		$baselinedCount = 0;
		$warnings = [];
		$unmatched = [];

		foreach ($categories as $category) {
			$report = $this->runner->analyse($category);
			$remaining = $report->getErrors();

			$path = $this->baselinePathFor($category);
			if ($path !== null && is_file($path)) {
				$result = Baseline::load($path)->apply($remaining);
				$remaining = $result->getRemaining();
				$baselinedCount += $result->getIgnoredCount();

				foreach ($result->getUnmatched() as $ignore) {
					$unmatched[] = $ignore;
				}
			}

			foreach ($remaining as $violation) {
				$errors[] = $violation;
			}

			$ignoredCount += $report->getIgnoredCount();

			foreach ($report->getWarnings() as $warning) {
				$warnings[] = $warning;
			}

			foreach ($report->getUnmatchedIgnores() as $ignore) {
				$unmatched[] = $ignore;
			}
		}

		$elapsed = microtime(true) - $start;
		$peakBytes = memory_get_peak_usage(true);

		$this->renderFindings($io, $errors);
		$this->renderSummaryTable($io, $errors);
		$this->renderStatus($io, $errors, $ignoredCount, $baselinedCount, $warnings);
		$this->renderWarningsAndUnmatched($io, $warnings, $unmatched);
		$this->renderFooter($io, $elapsed, $peakBytes);

		return $errors !== [] || $unmatched !== [] ? self::FAILURE : self::SUCCESS;
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

	/**
	 * @param list<Violation> $errors
	 * @param list<Warning>   $warnings
	 */
	private function renderStatus(
		SymfonyStyle $io,
		array $errors,
		int $ignoredCount,
		int $baselinedCount,
		array $warnings
	): void
	{
		$errorCount = count($errors);
		if ($errorCount > 0) {
			$io->error(sprintf('Found %d error%s', $errorCount, $errorCount === 1 ? '' : 's'));
		} else {
			$io->success('No errors');
		}

		$warningCount = count($warnings);
		$io->writeln(sprintf(
			'Ignored: %d   Baselined: %d   Warnings: %d%s',
			$ignoredCount,
			$baselinedCount,
			$warningCount,
			$warningCount > 0 ? ' ⚠️' : '',
		));
		$io->newLine();
	}

	/**
	 * @param list<Warning>      $warnings
	 * @param list<IgnoredError> $unmatched
	 */
	private function renderWarningsAndUnmatched(SymfonyStyle $io, array $warnings, array $unmatched): void
	{
		foreach ($warnings as $warning) {
			$io->warning($warning->getMessage());
		}

		foreach ($unmatched as $ignore) {
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

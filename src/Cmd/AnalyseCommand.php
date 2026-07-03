<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Cmd;

use Orisai\DbAudit\AnalyserCategory;
use Orisai\DbAudit\Ignore\Baseline;
use Orisai\DbAudit\Ignore\IgnoredError;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\TableViolationSource;
use Orisai\DbAudit\Report\Violation;
use Orisai\DbAudit\Report\Warning;
use Orisai\DbAudit\Runner\Runner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use function array_merge;
use function arsort;
use function count;
use function file_put_contents;
use function is_file;
use function max;
use function mb_strwidth;
use function memory_get_peak_usage;
use function microtime;
use function preg_replace;
use function preg_replace_callback;
use function sprintf;
use function str_repeat;

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
		$this->addOption(
			'generate-fix',
			null,
			InputOption::VALUE_REQUIRED,
			'Write migration SQL for fixable findings of the (single) category to this path',
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

		$generateFix = $input->getOption('generate-fix');
		if ($generateFix !== null) {
			if ($input->getOption('generate-baseline') === true) {
				$io->error('--generate-fix cannot be combined with --generate-baseline.');

				return self::FAILURE;
			}

			if (count($categories) > 1) {
				$io->error('--generate-fix needs a single --category (structure or data), not "all".');

				return self::FAILURE;
			}

			return $this->generateFix($io, $categories[0], (string) $generateFix);
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

	private function generateFix(SymfonyStyle $io, AnalyserCategory $category, string $path): int
	{
		$start = microtime(true);
		$report = $this->runner->generate($category);
		$elapsed = microtime(true) - $start;
		$peakBytes = memory_get_peak_usage(true);

		$this->renderFindings($io, $report->getUnfixable());

		$unfixableCount = count($report->getUnfixable());
		if ($report->hasUnfixable()) {
			$io->error(sprintf(
				'%d unfixable finding%s (SQL covers the rest)',
				$unfixableCount,
				$unfixableCount === 1 ? '' : 's',
			));
		} else {
			$generatedCount = $report->getGeneratedCount();
			$io->success(sprintf('Generated %d fix%s', $generatedCount, $generatedCount === 1 ? '' : 'es'));
		}

		foreach ($report->getAdvisories() as $advisory) {
			$io->note($advisory->getMessage());
		}

		$this->renderWarningsAndUnmatched($io, $report->getWarnings(), []);
		$this->renderFooter($io, $elapsed, $peakBytes);

		if (file_put_contents($path, $report->getSql()) === false) {
			$io->error('Failed to write ' . $path);

			return self::FAILURE;
		}

		$io->writeln('SQL written to ' . $path);

		return $report->hasUnfixable() ? self::FAILURE : self::SUCCESS;
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
		if ($errors === []) {
			return;
		}

		// Group by source (table + column), like PHPStan groups findings by file.
		$groups = [];
		foreach ($errors as $violation) {
			$groups[$this->sourceRef($violation)][] = $violation;
		}

		foreach ($groups as $source => $findings) {
			$header = '  ' . $this->bracketize($source, 'green');

			$body = [];
			foreach ($findings as $violation) {
				// A left emoji gutter (🔧 when fixable, blank otherwise) keeps message/🪪/💡 text aligned.
				$fix = $violation->isFixable() ? '🔧' : '  ';
				$body[] = '  ' . $fix . '  ' . $this->highlight($violation->getMessage());

				$body[] = '  <fg=white>🪪  ' . $violation->getKey() . '</>';

				if ($violation->getHint() !== null) {
					$body[] = '  💡  ' . $this->highlightHint($violation->getHint());
				}
			}

			$this->renderBlock($io, $header, $body);
		}
	}

	/**
	 * PHPStan-style horizontal rules (no vertical borders): rule, header, rule, body, rule.
	 *
	 * @param list<string> $body
	 */
	private function renderBlock(SymfonyStyle $io, string $header, array $body): void
	{
		$rule = $this->rule(array_merge([$header], $body));
		$io->writeln($rule);
		$io->writeln($header);
		$io->writeln($rule);
		foreach ($body as $line) {
			$io->writeln($line);
		}

		$io->writeln($rule);
		$io->newLine();
	}

	/**
	 * @param list<string> $lines
	 */
	private function rule(array $lines): string
	{
		$width = 0;
		foreach ($lines as $line) {
			$width = max($width, $this->visibleWidth($line));
		}

		return ' ' . str_repeat('─', $width);
	}

	private function visibleWidth(string $line): int
	{
		$stripped = preg_replace('#<[^>]+>#', '', $line) ?? $line;

		return mb_strwidth($stripped);
	}

	private function sourceRef(Violation $violation): string
	{
		$source = $violation->getSource();

		if ($source instanceof ColumnViolationSource) {
			return '[' . $source->getTable() . '][' . $source->getColumn() . ']';
		}

		if ($source instanceof TableViolationSource) {
			return '[' . $source->getTable() . ']';
		}

		return $source->toString();
	}

	private function bracketize(string $text, string $color): string
	{
		// `[table][column]` refs: white brackets, coloured content.
		$result = preg_replace_callback(
			'#\[([^\]]*)\]#',
			static fn (array $m): string => '<fg=white>[</><fg=' . $color . '>' . $m[1] . '</><fg=white>]</>',
			$text,
		);

		return $result ?? $text;
	}

	private function highlight(string $text): string
	{
		// Only explicit, delimited tokens — a bare-word match would colour "date" inside "outdated".
		$text = $this->bracketize($text, 'yellow');
		$text = preg_replace('#`[^`]*`#', '<fg=yellow>$0</>', $text) ?? $text;

		return preg_replace("#'[^']*'#", '<fg=blue>$0</>', $text) ?? $text;
	}

	private function highlightHint(string $text): string
	{
		// Hints stay white; only identifiers (yellow) and the runnable command (blue) are highlighted.
		$text = $this->bracketize($text, 'yellow');

		return preg_replace('#db-audit:\S+(?:\s+--\S+)*#', '<fg=blue>$0</>', $text) ?? $text;
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

		if ($unmatched === []) {
			return;
		}

		$io->writeln('  <fg=red>These ignored errors never matched — remove them from the baseline:</>');
		$io->newLine();

		foreach ($unmatched as $ignore) {
			$this->renderBlock($io, $this->ignoreHeader($ignore), $this->ignoreBody($ignore));
		}
	}

	private function ignoreHeader(IgnoredError $ignore): string
	{
		$table = $ignore->getTable();
		$column = $ignore->getColumn();

		if ($table !== null) {
			$ref = '[' . $table . ']' . ($column !== null ? '[' . $column . ']' : '');
		} elseif ($column !== null) {
			$ref = '[' . $column . ']';
		} else {
			$ref = '[any source]';
		}

		return '  ' . $this->bracketize($ref, 'red');
	}

	/**
	 * @return list<string>
	 */
	private function ignoreBody(IgnoredError $ignore): array
	{
		$rawMessage = $ignore->getRawMessage();
		$message = $ignore->getMessage();
		if ($rawMessage !== null) {
			$body = ['  🚫  ' . $this->highlight($rawMessage)];
		} elseif ($message !== null) {
			// A regex pattern, not a literal message — shown verbatim, without token highlighting.
			$body = ['  🚫  ' . $message];
		} else {
			$body = ['  🚫  <fg=gray>(matches any message)</>'];
		}

		if ($ignore->getKey() !== null) {
			$body[] = '  <fg=white>🪪  ' . $ignore->getKey() . '</>';
		}

		if ($ignore->getCount() !== null) {
			$body[] = '      <fg=gray>count: ' . $ignore->getCount() . '</>';
		}

		return $body;
	}

	private function renderFooter(SymfonyStyle $io, float $elapsed, int $peakBytes): void
	{
		$io->writeln(sprintf('⏱  Time: %.2fs   💾  Memory: %.1f MB', $elapsed, $peakBytes / 1_048_576));
	}

}

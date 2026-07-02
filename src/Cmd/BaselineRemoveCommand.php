<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Cmd;

use Orisai\DbAudit\AnalyserCategory;
use Orisai\DbAudit\Ignore\Baseline;
use Orisai\DbAudit\Ignore\BaselineFilter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function ctype_digit;
use function sprintf;

final class BaselineRemoveCommand extends Command
{

	/** @readonly */
	private ?string $structureBaselinePath;

	/** @readonly */
	private ?string $dataBaselinePath;

	public function __construct(?string $structureBaselinePath = null, ?string $dataBaselinePath = null)
	{
		parent::__construct();
		$this->structureBaselinePath = $structureBaselinePath;
		$this->dataBaselinePath = $dataBaselinePath;
	}

	public static function getDefaultName(): string
	{
		return 'db-audit:baseline:remove';
	}

	public static function getDefaultDescription(): string
	{
		return 'Remove matching entries from a baseline file';
	}

	protected function configure(): void
	{
		$this->addOption('category', 'c', InputOption::VALUE_REQUIRED, 'Choose "structure" or "data" (required)');
		$this->addOption('key', null, InputOption::VALUE_REQUIRED, 'Match entries with this identifier');
		$this->addOption('raw-message', null, InputOption::VALUE_REQUIRED, 'Match entries with this exact message');
		$this->addOption('count', null, InputOption::VALUE_REQUIRED, 'Match entries with this exact count');
		$this->addOption('table', null, InputOption::VALUE_REQUIRED, 'Match entries on this table');
		$this->addOption('column', null, InputOption::VALUE_REQUIRED, 'Match entries on this column');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$categoryOption = $this->option($input, 'category');
		$category = $categoryOption !== null ? AnalyserCategory::tryFrom($categoryOption) : null;
		if ($category === null) {
			$output->writeln(sprintf(
				'<error>Choose --category: "structure" or "data"%s.</error>',
				$categoryOption !== null ? sprintf(' (got "%s")', $categoryOption) : '',
			));

			return self::FAILURE;
		}

		$path = $category === AnalyserCategory::structure() ? $this->structureBaselinePath : $this->dataBaselinePath;
		if ($path === null) {
			$output->writeln(sprintf('<error>No baseline path configured for %s.</error>', $category->value));

			return self::FAILURE;
		}

		$key = $this->option($input, 'key');
		$rawMessage = $this->option($input, 'raw-message');
		$table = $this->option($input, 'table');
		$column = $this->option($input, 'column');

		$count = null;
		$countOption = $input->getOption('count');
		if ($countOption !== null) {
			$countString = (string) $countOption;
			if ($countString === '0' || !ctype_digit($countString)) {
				$output->writeln('<error>--count must be a positive integer.</error>');

				return self::FAILURE;
			}

			$count = (int) $countString;
		}

		if ($key === null && $rawMessage === null && $count === null && $table === null && $column === null) {
			$output->writeln(
				'<error>Provide at least one of --key, --raw-message, --count, --table or --column.</error>',
			);

			return self::FAILURE;
		}

		$removed = Baseline::remove($path, new BaselineFilter($key, $rawMessage, $count, $table, $column));

		$output->writeln(sprintf('Removed %d %s.', $removed, $removed === 1 ? 'entry' : 'entries'));

		return self::SUCCESS;
	}

	private function option(InputInterface $input, string $name): ?string
	{
		$value = $input->getOption($name);

		return $value !== null ? (string) $value : null;
	}

}

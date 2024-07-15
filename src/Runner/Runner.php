<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Runner;

use Orisai\DbAudit\Analyser;
use Orisai\DbAudit\AnalyserCategory;
use Orisai\DbAudit\Change\BasicMigrationStrategy;
use Orisai\DbAudit\Change\MigrationPlanner;
use Orisai\DbAudit\Change\MigrationStrategy;
use Orisai\DbAudit\Change\SchemaContext;
use Orisai\DbAudit\Change\TableSchema;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Driver\ServerInfo;
use Orisai\DbAudit\Driver\ServerInfoReader;
use Orisai\DbAudit\Ignore\IgnoreList;
use Orisai\DbAudit\Report\Violation;
use Orisai\DbAudit\Report\Warning;
use Orisai\DbAudit\Schema\CurrentColumnDefinition;
use Orisai\DbAudit\Schema\ForeignKeyGraph;
use Orisai\DbAudit\Schema\SchemaProvider;
use function array_values;
use function get_class;
use function sprintf;
use function strtolower;
use function strtoupper;
use function substr;

final class Runner
{

	private DbalAdapter $dbal;

	/** @var list<Analyser> */
	private array $analysers;

	private IgnoreList $structureIgnores;

	private IgnoreList $dataIgnores;

	private MigrationStrategy $strategy;

	private MigrationPlanner $planner;

	private ?ServerInfo $serverInfo = null;

	private ?ForeignKeyGraph $foreignKeyGraph = null;

	private ?SchemaContext $schemaContext = null;

	/**
	 * @param list<Analyser> $analysers
	 */
	public function __construct(
		DbalAdapter $dbal,
		array $analysers,
		?IgnoreList $structureIgnores = null,
		?IgnoreList $dataIgnores = null,
		?MigrationStrategy $strategy = null,
		?MigrationPlanner $planner = null
	)
	{
		$this->dbal = $dbal;
		$this->analysers = $analysers;
		$this->structureIgnores = $structureIgnores ?? new IgnoreList();
		$this->dataIgnores = $dataIgnores ?? new IgnoreList();
		$this->strategy = $strategy ?? new BasicMigrationStrategy($dbal);
		$this->planner = $planner ?? new MigrationPlanner();
	}

	public function analyse(?AnalyserCategory $only = null): AnalysisReport
	{
		$server = $this->getServerInfo();

		$errors = [];
		$ignoredCount = 0;
		$warnings = [];
		$unmatched = [];

		foreach ($this->categoriesToRun($only) as $category) {
			$violations = [];
			foreach ($this->analysers as $analyser) {
				if ($analyser->getCategory() !== $category) {
					continue;
				}

				if (!$this->supports($analyser, $server)) {
					$warnings[] = $this->unsupportedWarning($analyser, $server);

					continue;
				}

				foreach ($analyser->analyse()->getViolations() as $violation) {
					$violations[] = $violation;
				}
			}

			$result = $this->ignoresFor($category)->apply($violations);
			foreach ($result->getRemaining() as $violation) {
				$errors[] = $violation;
			}

			$ignoredCount += $result->getIgnoredCount();
			foreach ($result->getUnmatched() as $ignore) {
				$unmatched[] = $ignore;
			}
		}

		return new AnalysisReport($errors, $ignoredCount, $warnings, $unmatched);
	}

	public function generate(?AnalyserCategory $only = null): GenerationReport
	{
		$server = $this->getServerInfo();

		$sql = '';
		$generatedCount = 0;
		$unfixable = [];
		$advisories = [];
		$warnings = [];
		$changeViolations = [];

		foreach ($this->categoriesToRun($only) as $category) {
			$violations = [];
			foreach ($this->analysers as $analyser) {
				if ($analyser->getCategory() !== $category) {
					continue;
				}

				if (!$this->supports($analyser, $server)) {
					$warnings[] = $this->unsupportedWarning($analyser, $server);

					continue;
				}

				$result = $analyser->analyse();
				foreach ($result->getViolations() as $violation) {
					$violations[] = $violation;
				}

				foreach ($result->getAdvisories() as $advisory) {
					$advisories[] = $advisory;
				}
			}

			// A surviving violation either carries changes the composer renders, or — when it has none and is
			// not fixable — is reported as unfixable.
			foreach ($this->ignoresFor($category)->apply($violations)->getRemaining() as $violation) {
				if ($violation->getChanges() !== []) {
					$changeViolations[] = $violation;
				} elseif (!$violation->isFixable()) {
					$unfixable[] = $violation;
				}
			}
		}

		$plan = $this->planner->plan($changeViolations, $this->getForeignKeyGraph(), $this->getSchemaContext());
		$sql = $this->appendSql($sql, $this->strategy->render($plan));
		$generatedCount += $plan->getGeneratedCount();
		foreach ($plan->getConflicts() as $conflict) {
			$unfixable[] = $conflict;
		}

		foreach ($plan->getRefusals() as $refusal) {
			$unfixable[] = $refusal;
		}

		return new GenerationReport($sql, $generatedCount, $unfixable, $advisories, $warnings);
	}

	/**
	 * @param literal-string $sql
	 * @param literal-string $addition
	 * @return literal-string
	 */
	private function appendSql(string $sql, string $addition): string
	{
		if ($addition === '') {
			return $sql;
		}

		if ($sql !== '' && substr($sql, -1) !== "\n") {
			$sql .= "\n";
		}

		return $sql . $addition;
	}

	/**
	 * Every reportable violation, ignores not applied — used to (re)generate a baseline.
	 *
	 * @return list<Violation>
	 */
	public function collectErrors(?AnalyserCategory $only = null): array
	{
		$server = $this->getServerInfo();

		$violations = [];
		foreach ($this->categoriesToRun($only) as $category) {
			foreach ($this->analysers as $analyser) {
				if ($analyser->getCategory() !== $category || !$this->supports($analyser, $server)) {
					continue;
				}

				foreach ($analyser->analyse()->getViolations() as $violation) {
					$violations[] = $violation;
				}
			}
		}

		return $violations;
	}

	/**
	 * @return list<AnalyserCategory>
	 */
	private function categoriesToRun(?AnalyserCategory $only): array
	{
		return $only !== null ? [$only] : AnalyserCategory::cases();
	}

	private function ignoresFor(AnalyserCategory $category): IgnoreList
	{
		return $category === AnalyserCategory::structure() ? $this->structureIgnores : $this->dataIgnores;
	}

	private function supports(Analyser $analyser, ServerInfo $server): bool
	{
		foreach ($analyser->getSupportedDatabases() as $supported) {
			if ($supported->supports($server)) {
				return true;
			}
		}

		return false;
	}

	private function unsupportedWarning(Analyser $analyser, ServerInfo $server): Warning
	{
		$class = get_class($analyser);

		return new Warning(
			sprintf('%s does not support %s %s and was skipped.', $class, $server->engine->name, $server->version),
			$class,
		);
	}

	private function getServerInfo(): ServerInfo
	{
		return $this->serverInfo ??= (new ServerInfoReader($this->dbal))->read();
	}

	private function getForeignKeyGraph(): ForeignKeyGraph
	{
		return $this->foreignKeyGraph ??= (new SchemaProvider($this->dbal))->getForeignKeyGraph();
	}

	private function getSchemaContext(): SchemaContext
	{
		if ($this->schemaContext !== null) {
			return $this->schemaContext;
		}

		$provider = new SchemaProvider($this->dbal);
		$columnsByTable = $provider->getColumnsByTable();
		$statisticsByTable = $provider->getStatisticsByTable();

		$isMaria = $this->getServerInfo()->engine === DatabaseEngine::mariadb();

		$tables = [];
		foreach ($provider->getTables() as $table) {
			$name = $table['TABLE_NAME'];

			$columns = [];
			foreach ($columnsByTable[$name] ?? [] as $column) {
				$normalized = CurrentColumnDefinition::normalize($column, $isMaria);
				$normalized['dataType'] = strtolower($column['DATA_TYPE']);
				$columns[$column['COLUMN_NAME']] = $normalized;
			}

			$tables[$name] = new TableSchema(
				$columns,
				$this->buildIndexes($statisticsByTable[$name] ?? []),
				$table['ROW_FORMAT'] !== null ? strtoupper($table['ROW_FORMAT']) : null,
			);
		}

		$charsetMaxlen = [];
		foreach ($provider->getCharacterSets() as $row) {
			$charsetMaxlen[$row['CHARACTER_SET_NAME']] = $row['MAXLEN'];
		}

		$this->schemaContext = new SchemaContext($tables, $charsetMaxlen);

		return $this->schemaContext;
	}

	/**
	 * @param list<array{TABLE_NAME: string, INDEX_NAME: string, NON_UNIQUE: int, SEQ_IN_INDEX: int, COLUMN_NAME: string, SUB_PART: int|null}> $rows
	 * @return list<array{name: string, unique: bool, members: list<array{column: string, subPart: int|null}>}>
	 */
	private function buildIndexes(array $rows): array
	{
		$byIndex = [];
		foreach ($rows as $row) {
			$indexName = $row['INDEX_NAME'];
			if (!isset($byIndex[$indexName])) {
				$byIndex[$indexName] = [
					'name' => $indexName,
					'unique' => $row['NON_UNIQUE'] === 0,
					'members' => [],
				];
			}

			$byIndex[$indexName]['members'][] = [
				'column' => $row['COLUMN_NAME'],
				'subPart' => $row['SUB_PART'],
			];
		}

		return array_values($byIndex);
	}

}

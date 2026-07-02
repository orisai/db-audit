<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Change\DropIndexChange;
use Orisai\DbAudit\Report\AnalysisResult;
use Orisai\DbAudit\Report\TableViolationSource;
use Orisai\DbAudit\Report\Violation;
use Orisai\DbAudit\Schema\ColumnCharsetClass;
use Orisai\DbAudit\Schema\SchemaRequest;
use Orisai\DbAudit\Schema\TableExclude;
use function array_keys;
use function count;
use function sort;
use function strcmp;
use const SORT_STRING;

final class RedundantIndexMysqlAuditor extends RedundantIndexAuditor
{

	private const PrimaryName = 'PRIMARY';

	public function getSchemaRequest(): SchemaRequest
	{
		return new SchemaRequest(
			ColumnCharsetClass::any(),
			new TableExclude(),
			false,
			true,
			true,
		);
	}

	public function analyse(): AnalysisResult
	{
		$db = $this->schema->getDatabaseDefault()['name'];
		$statisticsByTable = $this->schema->getStatisticsByTable();

		$violations = [];
		foreach ($this->schema->getTables() as $table) {
			$tableName = $table['TABLE_NAME'];
			$stats = $statisticsByTable[$tableName] ?? [];
			if ($stats === []) {
				continue;
			}

			$indexes = $this->groupIndexes($stats);

			$names = array_keys($indexes);
			sort($names, SORT_STRING);

			foreach ($names as $name) {
				$index = $indexes[$name];

				// Only a non-unique, non-primary index can be flagged: PRIMARY and UNIQUE enforce a
				// constraint a covering non-unique index does not.
				if ($name === self::PrimaryName || $index['nonUnique'] === false) {
					continue;
				}

				$covering = $this->findCovering($index, $indexes);
				if ($covering === null) {
					continue;
				}

				$source = new TableViolationSource($db, null, $tableName);
				$violations[] = new Violation(
					'redundant_index',
					"Index '" . $name . "' on table " . $source->toString()
					. " is redundant: its columns are a prefix of index '" . $covering . "'.",
					$source,
					true,
					"Drop the redundant index '" . $name . "'.",
					[new DropIndexChange($db, $tableName, $name)],
				);
			}
		}

		return new AnalysisResult($violations);
	}

	/**
	 * @param list<array{TABLE_NAME: string, INDEX_NAME: string, NON_UNIQUE: int, SEQ_IN_INDEX: int, COLUMN_NAME: string, SUB_PART: int|null}> $stats
	 * @return array<string, array{name: string, nonUnique: bool, columns: list<array{0: string, 1: int|null}>}>
	 */
	private function groupIndexes(array $stats): array
	{
		$indexes = [];
		foreach ($stats as $stat) {
			$name = $stat['INDEX_NAME'];
			if (!isset($indexes[$name])) {
				$indexes[$name] = [
					'name' => $name,
					'nonUnique' => $stat['NON_UNIQUE'] === 1,
					'columns' => [],
				];
			}

			$indexes[$name]['columns'][] = [$stat['COLUMN_NAME'], $stat['SUB_PART']];
		}

		return $indexes;
	}

	/**
	 * Returns the name of the index covering $candidate, or null when none does. The covering index may be
	 * unique or non-unique: a non-unique candidate subsumed by any longer-or-equal index is redundant for
	 * lookups. For exact duplicates the name tie-break keeps the earlier-sorting one, so a duplicate is only
	 * covered by an equal index that sorts before it (and never by another equal duplicate that sorts after).
	 *
	 * @param array{name: string, nonUnique: bool, columns: list<array{0: string, 1: int|null}>}        $candidate
	 * @param array<string, array{name: string, nonUnique: bool, columns: list<array{0: string, 1: int|null}>}> $indexes
	 */
	private function findCovering(array $candidate, array $indexes): ?string
	{
		$covering = null;
		foreach ($indexes as $name => $index) {
			if ($name === $candidate['name']) {
				continue;
			}

			if (!$this->isPrefix($candidate['columns'], $index['columns'])) {
				continue;
			}

			if (count($candidate['columns']) === count($index['columns'])) {
				$otherIsCandidate = $name !== self::PrimaryName && $index['nonUnique'];
				if ($otherIsCandidate && strcmp($candidate['name'], $name) < 0) {
					continue;
				}
			}

			if ($covering === null || strcmp($name, $covering) < 0) {
				$covering = $name;
			}
		}

		return $covering;
	}

	/**
	 * @param list<array{0: string, 1: int|null}> $prefix
	 * @param list<array{0: string, 1: int|null}> $columns
	 */
	private function isPrefix(array $prefix, array $columns): bool
	{
		if (count($prefix) > count($columns)) {
			return false;
		}

		foreach ($prefix as $i => $column) {
			if ($columns[$i][0] !== $column[0] || $columns[$i][1] !== $column[1]) {
				return false;
			}
		}

		return true;
	}

}

<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Collation\CollationProfile;
use Orisai\DbAudit\Collation\CollationResolver;
use Orisai\DbAudit\Collation\CollationTarget;
use Orisai\DbAudit\Collation\CollationTargetPolicy;
use Orisai\DbAudit\Driver\ServerInfoReader;
use Orisai\DbAudit\Report\AnalysisResult;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;
use Orisai\DbAudit\Schema\ColumnCharsetClass;
use Orisai\DbAudit\Schema\SchemaRequest;
use function array_keys;
use function array_values;
use function implode;
use function ksort;
use function preg_match;

final class UniqueIndexCollationCollisionMysqlAuditor extends UniqueIndexCollationCollisionAuditor
{

	/**
	 * Every column of the in-scope tables is read (any) so a composite unique index's non-converting members
	 * (an int sibling, an already-utf8mb4 sibling) are rendered verbatim in the tuple probe; statistics give
	 * the unique-index membership. Foreign keys are irrelevant to a read-only collision report.
	 */
	public function getSchemaRequest(): SchemaRequest
	{
		return new SchemaRequest(
			ColumnCharsetClass::any(),
			$this->config->getExcludeTables(),
			false,
			true,
			true,
		);
	}

	public function analyse(): AnalysisResult
	{
		$this->primeOwnedSchema();

		$server = (new ServerInfoReader($this->dbal))->read();
		$available = [];
		foreach ($this->schema->getCollations() as $collation) {
			if ($collation['CHARACTER_SET_NAME'] === 'utf8mb4') {
				$available[] = $collation['COLLATION_NAME'];
			}
		}

		$resolver = new CollationResolver($server, $available);
		$dbName = $this->schema->getDatabaseDefault()['name'];
		$exclude = $this->config->getExcludeTables();

		$columnsByTable = $this->schema->getColumnsByTable();
		$statisticsByTable = $this->schema->getStatisticsByTable();

		$violations = [];
		foreach ($this->schema->getTables() as $table) {
			$name = $table['TABLE_NAME'];
			if ($exclude->matches($name)) {
				continue;
			}

			$convertingTargets = $this->convertingTargets($columnsByTable[$name] ?? [], $resolver);
			if ($convertingTargets === []) {
				continue;
			}

			$columnTypes = [];
			foreach ($columnsByTable[$name] ?? [] as $column) {
				$columnTypes[$column['COLUMN_NAME']] = $column['COLUMN_TYPE'];
			}

			foreach ($this->uniqueIndexMembers($statisticsByTable[$name] ?? []) as $indexName => $members) {
				$convertingMembers = [];
				foreach ($members as $member) {
					if (isset($convertingTargets[$member])) {
						$convertingMembers[$member] = $convertingTargets[$member];
					}
				}

				if ($convertingMembers === [] || !$this->hasIndexCollision($name, $members, $convertingMembers)) {
					continue;
				}

				$violations[] = $this->collisionViolation(
					$dbName,
					$name,
					$indexName,
					$convertingMembers,
					$columnTypes,
				);
			}
		}

		return new AnalysisResult($violations);
	}

	/**
	 * The columns whose conversion changes the collation in a NON-order-preserving way — the only ones that
	 * can turn two currently-distinct unique keys into one. An order-preserving conversion (utf8mb3 -> its
	 * utf8mb4 namesake) keeps the bytes and the collation algorithm, so it never collides and is excluded.
	 *
	 * @param list<array{
	 *     TABLE_NAME: string, COLUMN_NAME: string, COLUMN_TYPE: string, DATA_TYPE: string,
	 *     CHARACTER_SET_NAME: string|null, COLLATION_NAME: string|null,
	 *     IS_NULLABLE: string, COLUMN_DEFAULT: string|null, EXTRA: string, COLUMN_COMMENT: string,
	 *     GENERATION_EXPRESSION: string|null, CHARACTER_MAXIMUM_LENGTH: int|null, ORDINAL_POSITION: int
	 * }> $columns
	 * @return array<string, CollationTarget>
	 */
	private function convertingTargets(array $columns, CollationResolver $resolver): array
	{
		$policy = $this->config->getTargetPolicy();

		$targets = [];
		foreach ($columns as $column) {
			$charset = $column['CHARACTER_SET_NAME'];
			if ($charset === null || !$this->shouldConvertCharset($charset)) {
				continue;
			}

			$collation = (string) $column['COLLATION_NAME'];
			$profile = CollationProfile::fromCollationName($collation);
			if ($profile === null) {
				continue;
			}

			$target = $resolver->resolve($charset, $collation, $profile, $policy);
			if ($target === null) {
				continue;
			}

			if ($target->collation === $collation && $charset === 'utf8mb4') {
				continue;
			}

			if ($target->orderPreserving) {
				continue;
			}

			$targets[$column['COLUMN_NAME']] = $target;
		}

		return $targets;
	}

	/**
	 * Flattens the unique-index statistics (INFORMATION_SCHEMA reports a PRIMARY KEY as a unique index too)
	 * into per-index member lists ordered by SEQ_IN_INDEX, so the tuple probe groups by the whole key.
	 *
	 * @param list<array{TABLE_NAME: string, INDEX_NAME: string, NON_UNIQUE: int, SEQ_IN_INDEX: int, COLUMN_NAME: string, SUB_PART: int|null}> $statistics
	 * @return array<string, list<string>>
	 */
	private function uniqueIndexMembers(array $statistics): array
	{
		$bySeq = [];
		foreach ($statistics as $row) {
			if ($row['NON_UNIQUE'] !== 0) {
				continue;
			}

			$bySeq[$row['INDEX_NAME']][$row['SEQ_IN_INDEX']] = $row['COLUMN_NAME'];
		}

		$members = [];
		foreach ($bySeq as $index => $columnsBySeq) {
			ksort($columnsBySeq);
			$members[$index] = array_values($columnsBySeq);
		}

		return $members;
	}

	/**
	 * Probes a single unique index for a post-conversion collision at the tuple level.
	 *
	 * Uniqueness is on the whole index tuple, so the GROUP BY spans every member column: a converting member
	 * is grouped by CONVERT(col USING <target charset>) COLLATE <target collation> (its post-migration value),
	 * a non-converting one verbatim. A NULL in any member excludes the row from uniqueness (NULLs never
	 * compare equal), so such rows are filtered out before grouping. An identifier token that is not a plain
	 * word is rejected and treated as a collision rather than interpolated into SQL.
	 *
	 * @param list<string> $members
	 * @param array<string, CollationTarget> $convertingMembers
	 */
	private function hasIndexCollision(string $table, array $members, array $convertingMembers): bool
	{
		$sql = $this->collisionProbeSql($table, $members, $convertingMembers);
		if ($sql === null) {
			return true;
		}

		// phpcs:ignore SlevomatCodingStandard.PHP.RequireExplicitAssertion.RequiredExplicitAssertion
		/** @var literal-string $literalSql */
		$literalSql = $sql;

		return $this->dbal->query($literalSql) !== [];
	}

	/**
	 * @param list<string> $members
	 * @param array<string, CollationTarget> $convertingMembers
	 * @return string|null null = a charset/collation token was not a plain word, so it cannot be safely
	 *                     interpolated; the caller treats that as a collision rather than building the query
	 */
	private function collisionProbeSql(string $table, array $members, array $convertingMembers): ?string
	{
		$groupExprs = [];
		$notNullConditions = [];
		foreach ($members as $member) {
			$escaped = $this->dbal->escapeIdentifier($member);
			$notNullConditions[] = $escaped . ' IS NOT NULL';

			$target = $convertingMembers[$member] ?? null;
			if ($target === null) {
				$groupExprs[] = $escaped;

				continue;
			}

			$collationToken = $target->collation;
			$charsetToken = $target->charset;
			if (
				preg_match('#^[A-Za-z0-9_]+$#', $collationToken) !== 1
				|| preg_match('#^[A-Za-z0-9_]+$#', $charsetToken) !== 1
			) {
				return null;
			}

			$groupExprs[] = 'CONVERT(' . $escaped . ' USING ' . $charsetToken . ') COLLATE ' . $collationToken;
		}

		return 'SELECT 1 FROM ' . $this->dbal->escapeIdentifier($table)
			. ' WHERE ' . implode(' AND ', $notNullConditions)
			. ' GROUP BY ' . implode(', ', $groupExprs)
			. ' HAVING COUNT(*) > 1 LIMIT 1';
	}

	/**
	 * @param array<string, CollationTarget> $convertingMembers
	 * @param array<string, string> $columnTypes
	 */
	private function collisionViolation(
		string $dbName,
		string $table,
		string $indexName,
		array $convertingMembers,
		array $columnTypes
	): Violation
	{
		$columnNames = array_keys($convertingMembers);
		$firstColumn = $columnNames[0];

		$targets = [];
		foreach ($convertingMembers as $target) {
			$targets[$target->collation] = true;
		}

		$source = new ColumnViolationSource($dbName, null, $table, $firstColumn);
		if (isset($columnTypes[$firstColumn])) {
			$source->setColumnType($columnTypes[$firstColumn]);
		}

		return new Violation(
			'unique_index_collation_collision',
			'Unique index \'' . $indexName . '\' on table [' . $table . '] has row data that collides under the'
			. ' target collation ' . implode(', ', array_keys($targets)) . ': converting column(s) '
			. implode(', ', $columnNames) . ' would make currently-distinct keys equal, creating duplicate-key'
			. ' conflicts.',
			$source,
			false,
			'Deduplicate the colliding rows before setting forceUniqueIndexConversion to convert this index.',
		);
	}

	private function shouldConvertCharset(string $charset): bool
	{
		if ($charset === 'utf8mb4') {
			return $this->config->getTargetPolicy() === CollationTargetPolicy::modernize();
		}

		if ($charset === 'utf8mb3' || $charset === 'utf8') {
			return $this->config->convertsUtf8mb3();
		}

		return true;
	}

}

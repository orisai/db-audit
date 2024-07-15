<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Change;

use Orisai\DbAudit\Report\TableViolationSource;
use Orisai\DbAudit\Report\Violation;
use Orisai\DbAudit\Schema\ForeignKeyConstraint;
use Orisai\DbAudit\Schema\ForeignKeyGraph;
use Orisai\DbAudit\Schema\LegacyCharset;
use function array_keys;
use function count;
use function explode;
use function implode;
use function in_array;
use function ksort;
use function preg_match;
use function serialize;
use function sort;
use function strpos;
use function strtolower;
use function substr;
use function usort;

/**
 * Resolves the change requests tagged on fixable violations into a {@see ResolvedPlan}: it deduplicates them, refuses
 * conflicts (two requests with the same attribute on one table but different clauses) by reporting each as an unfixable
 * conflict, orders each table's clauses, and — given the foreign-key graph — schedules the foreign keys whose columns
 * change to be dropped before and re-added after, wrapped in `foreign_key_checks = 0`. {@see DatabaseDefaultChange}
 * is routed to the resolved plan's database default; a {@see ColumnTargetChange} needing a latin1 two-step contributes
 * its column to each table's prefix ALTERs in addition to the combined MODIFY.
 */
final class MigrationPlanner
{

	/** @readonly */
	private bool $autoUpgradeRowFormat;

	public function __construct(bool $autoUpgradeRowFormat = true)
	{
		$this->autoUpgradeRowFormat = $autoUpgradeRowFormat;
	}

	/**
	 * @param list<Violation> $violations
	 */
	public function plan(
		array $violations,
		?ForeignKeyGraph $foreignKeyGraph = null,
		?SchemaContext $schema = null
	): ResolvedPlan
	{
		/** @var array<string, array<string, true>> $clauses */
		$clauses = [];
		/** @var array<string, ChangeRequest> $representative */
		$representative = [];
		/** @var array<string, array{0: string, 1: string}> $location */
		$location = [];

		/** @var array<string, non-empty-list<ColumnTargetChange>> $columnGroups */
		$columnGroups = [];
		/** @var array<string, array{0: string, 1: string, 2: string}> $columnLocation */
		$columnLocation = [];

		$databaseDefault = null;
		/** @var array<string, array<string, DatabaseDefaultChange>> $dbDefaultGroups */
		$dbDefaultGroups = [];
		/** @var array<string, list<ColumnTargetChange>> $prefixAlters */
		$prefixAlters = [];

		foreach ($violations as $violation) {
			foreach ($violation->getChanges() as $change) {
				if ($change instanceof DatabaseDefaultChange) {
					$dbDefaultGroups[$change->getDatabase()][$change->getComparisonKey()] = $change;

					continue;
				}

				// Same-column deltas are merged onto the current definition below, so they collect per column
				// instead of joining the generic (table, attribute) dedup other change types keep using.
				if ($change instanceof ColumnTargetChange) {
					$colKey = $change->getDatabase() . "\0" . $change->getTable() . "\0" . $change->getColumn();
					$columnGroups[$colKey][] = $change;
					$columnLocation[$colKey] = [$change->getDatabase(), $change->getTable(), $change->getColumn()];

					continue;
				}

				$objectKey = $change->getDatabase() . "\0" . $change->getTable() . "\0" . $change->getAttribute();
				$clauses[$objectKey][$change->getComparisonKey()] = true;
				$representative[$objectKey] = $change;
				$location[$objectKey] = [$change->getDatabase(), $change->getTable()];
			}
		}

		ksort($clauses);

		$surviving = [];
		$conflicts = [];
		$fixCount = 0;
		foreach ($clauses as $objectKey => $clauseSet) {
			[$database, $table] = $location[$objectKey];

			if (count($clauseSet) > 1) {
				$competing = array_keys($clauseSet);
				sort($competing);
				$conflicts[] = new Violation(
					'change.conflict',
					'Conflicting changes for table `' . $table . '`: ' . implode(' vs ', $competing) . '.',
					new TableViolationSource($database, null, $table),
				);

				continue;
			}

			$change = $representative[$objectKey];
			$surviving[] = $change;
			if ($change->countsAsFix()) {
				$fixCount++;
			}
		}

		foreach ($columnGroups as $colKey => $deltas) {
			[$database, $table, $column] = $columnLocation[$colKey];

			$current = null;
			if ($schema !== null) {
				$tableSchema = $schema->getTable($table);
				if ($tableSchema !== null) {
					$current = $tableSchema->getColumns()[$column] ?? null;
				}
			}

			if ($current === null) {
				// No known current definition (schema-less unit callers, or an unknown column): fall back to the
				// generic dedup — a single change passes through, differing changes conflict.
				$effective = $this->dedupSchemalessColumn($deltas, $database, $table, $conflicts);
			} else {
				$merged = $this->mergeColumnDeltas($deltas, $current);
				if ($merged[1]) {
					$conflicts[] = new Violation(
						'change.conflict',
						'Conflicting changes for table `' . $table . '`: column `' . $column
						. '` is set to incompatible values.',
						new TableViolationSource($database, null, $table),
					);

					continue;
				}

				$effective = $merged[0];
			}

			if ($effective === null) {
				continue;
			}

			if ($effective->hasTwoStep()) {
				$prefixAlters[$database . "\0" . $table][] = $effective;
			}

			$surviving[] = $effective;
			if ($effective->countsAsFix()) {
				$fixCount++;
			}
		}

		foreach ($dbDefaultGroups as $database => $keyedChanges) {
			if (count($keyedChanges) > 1) {
				$competing = array_keys($keyedChanges);
				sort($competing);
				$conflicts[] = new Violation(
					'change.conflict',
					'Conflicting changes for database `' . $database . '` default: ' . implode(
						' vs ',
						$competing,
					) . '.',
					new TableViolationSource($database, null, ''),
				);

				continue;
			}

			foreach ($keyedChanges as $singleChange) {
				$databaseDefault = $singleChange;

				break;
			}
		}

		if ($databaseDefault !== null) {
			$fixCount++;
		}

		// Align widens/charset-drives FK endpoints before the key-length verdict, so a widened child is
		// checked against the 3072-byte limit and reconcile sees the aligned state. Both later passes run
		// post-ignore over the final surviving set, refusal first so reconcile (and the row-format bump) see
		// the columns that survive the >3072 key-length refusal. Refusing only ever drops conversions, so it
		// can never widen another index — a single refusal pass before reconcile's own fixpoint is sufficient.
		$refusals = [];
		$this->alignForeignKeyEndpoints($surviving, $foreignKeyGraph, $schema);
		$this->refuseOverlongIndexes($surviving, $fixCount, $refusals, $schema);
		$this->reconcileForeignKeyEndpoints($surviving, $fixCount, $refusals, $foreignKeyGraph, $schema);
		$prefixAlters = $this->filterPrefixAlters($prefixAlters, $surviving);

		$this->applyRowFormat($surviving, $schema);

		$foreignKeys = $this->foreignKeysToRebuild($surviving, $foreignKeyGraph);

		return new ResolvedPlan(
			$this->groupByTable($surviving, $prefixAlters),
			$fixCount,
			$conflicts,
			$foreignKeys,
			$foreignKeys !== [],
			$databaseDefault,
			$refusals,
		);
	}

	/**
	 * Schema-less fallback for unit callers only — production always has a SchemaContext.
	 * A raw sparse delta passed here would render an incomplete MODIFY; the Runner never takes this path.
	 *
	 * @param non-empty-list<ColumnTargetChange> $deltas
	 * @param list<Violation>                    $conflicts
	 */
	private function dedupSchemalessColumn(
		array $deltas,
		string $database,
		string $table,
		array &$conflicts
	): ?ColumnTargetChange
	{
		$keys = [];
		foreach ($deltas as $delta) {
			$keys[$delta->getComparisonKey()] = true;
		}

		if (count($keys) > 1) {
			$competing = array_keys($keys);
			sort($competing);
			$conflicts[] = new Violation(
				'change.conflict',
				'Conflicting changes for table `' . $table . '`: ' . implode(' vs ', $competing) . '.',
				new TableViolationSource($database, null, $table),
			);

			return null;
		}

		return $deltas[0];
	}

	/**
	 * Builds the effective column by merging the group's deltas onto the current definition, one field at a time:
	 * no delta sets a field → the current value survives; the deltas that set it must agree → that value; two set it
	 * to different values → a conflict (second tuple element true). A full change (every field present) reproduces
	 * itself. The effective change is returned only when it differs from current in at least one field, else null.
	 *
	 * @param non-empty-list<ColumnTargetChange>                                                                                                                                                                                                     $deltas
	 * @param array{type: string, charset: string|null, collation: string|null, nullable: bool, default: array{text: string, isExpression: bool}|null, onUpdateCurrentTimestamp: bool, comment: string|null, generated: array{expression: string, stored: bool}|null, charLength: int|null, dataType: string} $current
	 * @return array{0: ColumnTargetChange|null, 1: bool}
	 */
	private function mergeColumnDeltas(array $deltas, array $current): array
	{
		$conflict = false;

		$typeEntries = [];
		$charsetCollationEntries = [];
		$nullableEntries = [];
		$defaultEntries = [];
		$onUpdateEntries = [];
		$commentEntries = [];
		$generatedEntries = [];
		$twoStepEntries = [];
		foreach ($deltas as $delta) {
			$typeEntries[] = [$delta->hasType(), $delta->getType()];
			$charsetCollationEntries[] = [$delta->hasCharsetCollation(), [$delta->getCharset(), $delta->getCollation()]];
			$nullableEntries[] = [$delta->hasNullable(), $delta->isNullable()];
			$defaultEntries[] = [$delta->hasDefault(), $delta->getDefault()];
			$onUpdateEntries[] = [$delta->hasOnUpdate(), $delta->hasOnUpdateCurrentTimestamp()];
			$commentEntries[] = [$delta->hasComment(), $delta->getComment()];
			$generatedEntries[] = [$delta->hasGenerated(), $delta->getGenerated()];
			$twoStepEntries[] = [$delta->hasTwoStep(), $delta->getBinaryTwoStepType()];
		}

		$type = $this->resolveField($typeEntries, $current['type'], $conflict);
		$charsetCollation = $this->resolveField(
			$charsetCollationEntries,
			[$current['charset'], $current['collation']],
			$conflict,
		);
		$nullable = $this->resolveField($nullableEntries, $current['nullable'], $conflict);
		$default = $this->resolveField($defaultEntries, $current['default'], $conflict);
		$onUpdate = $this->resolveField($onUpdateEntries, $current['onUpdateCurrentTimestamp'], $conflict);
		$comment = $this->resolveField($commentEntries, $current['comment'], $conflict);
		$generated = $this->resolveField($generatedEntries, $current['generated'], $conflict);
		$twoStep = $this->resolveField($twoStepEntries, null, $conflict);

		if ($conflict) {
			return [null, true];
		}

		$charset = $charsetCollation[0];
		$collation = $charsetCollation[1];
		$charLength = $this->effectiveCharLength($type, $current['charLength'], $deltas);

		$effective = ColumnTargetChange::effective(
			$deltas[0]->getDatabase(),
			$deltas[0]->getTable(),
			$deltas[0]->getColumn(),
			$type,
			$charset,
			$collation,
			$nullable,
			$default,
			$onUpdate,
			$comment,
			$generated,
			$charLength,
			$twoStep,
		);

		$differs = $type !== $current['type']
			|| $charset !== $current['charset']
			|| $collation !== $current['collation']
			|| $nullable !== $current['nullable']
			|| serialize($default) !== serialize($current['default'])
			|| $onUpdate !== $current['onUpdateCurrentTimestamp']
			|| $comment !== $current['comment']
			|| serialize($generated) !== serialize($current['generated'])
			|| $twoStep !== null;

		return [$differs ? $effective : null, false];
	}

	/**
	 * @template T
	 * @param list<array{0: bool, 1: T}> $entries
	 * @param T                          $current
	 * @return T
	 */
	private function resolveField(array $entries, $current, bool &$conflict)
	{
		$chosen = $current;
		$have = false;
		foreach ($entries as $entry) {
			if ($entry[0] !== true) {
				continue;
			}

			if (!$have) {
				$chosen = $entry[1];
				$have = true;
			} elseif (serialize($chosen) !== serialize($entry[1])) {
				$conflict = true;
			}
		}

		return $chosen;
	}

	/**
	 * A full change carries its own char length (even null), so the effective reproduces it exactly; a sparse delta
	 * carries none, so the length is read back from the effective char/varchar type — the read-side rule that keeps
	 * the key-length and row-format passes seeing the migrated column's real byte width.
	 *
	 * @param non-empty-list<ColumnTargetChange> $deltas
	 */
	private function effectiveCharLength(string $type, ?int $currentCharLength, array $deltas): ?int
	{
		foreach ($deltas as $delta) {
			if ($delta->hasCharLength()) {
				return $delta->getEffectiveCharLength();
			}
		}

		if (preg_match('#^(?:var)?char\s*\((\d+)\)#', strtolower($type), $match) === 1) {
			return (int) $match[1];
		}

		return $currentCharLength;
	}

	/**
	 * @param list<ChangeRequest>                     $changes
	 * @param array<string, list<ColumnTargetChange>> $prefixAlters
	 * @return list<TablePlan>
	 */
	private function groupByTable(array $changes, array $prefixAlters): array
	{
		/** @var array<string, array{table: string, changes: list<array{int, string, ChangeRequest}>}> $byTable */
		$byTable = [];
		foreach ($changes as $change) {
			$key = $change->getDatabase() . "\0" . $change->getTable();
			$byTable[$key]['table'] = $change->getTable();
			$byTable[$key]['changes'][] = [$change->getSortKey(), $change->getComparisonKey(), $change];
		}

		foreach ($prefixAlters as $key => $prefixes) {
			if (!isset($byTable[$key])) {
				$table = explode("\0", $key)[1];
				$byTable[$key] = ['table' => $table, 'changes' => []];
			}
		}

		ksort($byTable);

		$tables = [];
		foreach ($byTable as $key => $entry) {
			$ordered = $entry['changes'];
			usort($ordered, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

			$orderedChanges = [];
			foreach ($ordered as $item) {
				$orderedChanges[] = $item[2];
			}

			$tables[] = new TablePlan($entry['table'], $orderedChanges, $prefixAlters[$key] ?? []);
		}

		return $tables;
	}

	/**
	 * @param list<ChangeRequest> $surviving
	 */
	private function applyRowFormat(array &$surviving, ?SchemaContext $schema): void
	{
		if ($schema === null) {
			return;
		}

		/** @var array<string, list<ColumnTargetChange>> $byTable */
		$byTable = [];
		foreach ($surviving as $change) {
			if (!($change instanceof ColumnTargetChange)) {
				continue;
			}

			$key = $change->getDatabase() . "\0" . $change->getTable();
			$byTable[$key][] = $change;
		}

		/** @var array<string, array{db: string, table: string}> $toInjectDynamic */
		$toInjectDynamic = [];

		foreach ($byTable as $tableKey => $changes) {
			$db = $changes[0]->getDatabase();
			$table = $changes[0]->getTable();
			$tableSchema = $schema->getTable($table);

			if ($tableSchema === null) {
				continue;
			}

			$tableColumns = $tableSchema->getColumns();
			$rowFormat = $tableSchema->getRowFormat();

			if (!in_array($rowFormat, ['COMPACT', 'REDUNDANT'], true)) {
				continue;
			}

			/** @var array<string, array{length: int|null, maxBytes: int}> $migratedInfo */
			$migratedInfo = [];
			foreach ($changes as $change) {
				$colName = $change->getColumn();
				$currentCol = $tableColumns[$colName] ?? null;
				$currentCharset = $currentCol !== null ? $currentCol['charset'] : null;
				$currentLength = $currentCol !== null ? $currentCol['charLength'] : null;

				$effectiveCharset = $change->getTargetCharset() ?? $currentCharset;
				$effectiveLength = $change->getEffectiveCharLength() ?? $currentLength;
				$maxBytes = $effectiveCharset !== null ? $schema->getCharsetMaxlen($effectiveCharset) : 1;

				$migratedInfo[$colName] = ['length' => $effectiveLength, 'maxBytes' => $maxBytes];
			}

			$needsDynamic = false;

			foreach ($tableSchema->getIndexes() as $index) {
				$members = $index['members'];
				$maxMigratedPerCol = 0;
				$hasMigrated = false;

				foreach ($members as $member) {
					$colName = $member['column'];
					if (!isset($migratedInfo[$colName])) {
						continue;
					}

					$hasMigrated = true;
					$effectiveLength = $migratedInfo[$colName]['length'] ?? 0;
					$maxBytes = $migratedInfo[$colName]['maxBytes'];
					$memberBytes = ($member['subPart'] ?? $effectiveLength) * $maxBytes;
					if ($memberBytes > $maxMigratedPerCol) {
						$maxMigratedPerCol = $memberBytes;
					}
				}

				if ($hasMigrated && $maxMigratedPerCol > 767) {
					$needsDynamic = true;

					break;
				}
			}

			if ($needsDynamic && $this->autoUpgradeRowFormat) {
				$toInjectDynamic[$tableKey] = ['db' => $db, 'table' => $table];
			}
		}

		foreach ($toInjectDynamic as $info) {
			$surviving[] = new RawClauseChange(
				$info['db'],
				$info['table'],
				'row_format',
				'ROW_FORMAT = DYNAMIC',
				10,
				false,
			);
		}
	}

	/**
	 * Refuses every column conversion whose index would exceed MySQL's 3072-byte key length limit after the change
	 * (the hard ceiling no row format can lift). Per index the post-migration key bytes are summed over its members
	 * — a converting member at its target charset, an unchanged string member at its current charset, a non-string
	 * member at {@see nonStringIndexBytes()} — and when the total overflows, every converting member of that index
	 * is dropped from the surviving set and reported. A member counts as converting when its target charset/collation
	 * or its effective char/varchar length differs from current, so a pure-length widen (e.g. a child FK column
	 * widened to its parent's length) that overflows the key is held back alongside charset conversions.
	 *
	 * @param list<ChangeRequest> $surviving
	 * @param list<Violation>     $refusals
	 */
	private function refuseOverlongIndexes(
		array &$surviving,
		int &$fixCount,
		array &$refusals,
		?SchemaContext $schema
	): void
	{
		if ($schema === null) {
			return;
		}

		/** @var array<string, array{table: string, columns: array<string, ColumnTargetChange>}> $byTable */
		$byTable = [];
		foreach ($surviving as $change) {
			if (!($change instanceof ColumnTargetChange)) {
				continue;
			}

			$key = $change->getDatabase() . "\0" . $change->getTable();
			$byTable[$key]['table'] = $change->getTable();
			$byTable[$key]['columns'][$change->getColumn()] = $change;
		}

		/** @var array<string, array<string, string>> $toDrop */
		$toDrop = [];
		foreach ($byTable as $tableKey => $entry) {
			$tableSchema = $schema->getTable($entry['table']);
			if ($tableSchema === null) {
				continue;
			}

			$columns = $tableSchema->getColumns();
			$migrated = $entry['columns'];

			foreach ($tableSchema->getIndexes() as $index) {
				$touchesMigrated = false;
				$totalBytes = 0;
				foreach ($index['members'] as $member) {
					$name = $member['column'];
					$change = $migrated[$name] ?? null;
					if ($change !== null) {
						$touchesMigrated = true;
					}

					$totalBytes += $this->indexMemberBytes($member, $change, $columns[$name] ?? null, $schema);
				}

				if (!$touchesMigrated || $totalBytes <= 3_072) {
					continue;
				}

				foreach ($index['members'] as $member) {
					$name = $member['column'];
					$change = $migrated[$name] ?? null;
					if ($change === null || isset($toDrop[$tableKey][$name])) {
						continue;
					}

					$currentCol = $columns[$name] ?? null;
					$converts = $currentCol === null
						|| $change->getTargetCharset() !== $currentCol['charset']
						|| $change->getCollation() !== $currentCol['collation']
						|| $change->getEffectiveCharLength() !== $currentCol['charLength'];

					if ($converts) {
						$toDrop[$tableKey][$name] = $index['name'];
					}
				}
			}
		}

		if ($toDrop === []) {
			return;
		}

		$kept = [];
		foreach ($surviving as $change) {
			if ($change instanceof ColumnTargetChange) {
				$indexName = $toDrop[$change->getDatabase() . "\0" . $change->getTable()][$change->getColumn()] ?? null;
				if ($indexName !== null) {
					$fixCount--;
					$refusals[] = $this->indexTooLongViolation($change, $indexName);

					continue;
				}
			}

			$kept[] = $change;
		}

		$surviving = $kept;
	}

	/**
	 * @param array{column: string, subPart: int|null} $member
	 * @param array{type: string, charset: string|null, collation: string|null, nullable: bool, default: array{text: string, isExpression: bool}|null, onUpdateCurrentTimestamp: bool, comment: string|null, generated: array{expression: string, stored: bool}|null, charLength: int|null, dataType: string}|null $current
	 */
	private function indexMemberBytes(
		array $member,
		?ColumnTargetChange $change,
		?array $current,
		SchemaContext $schema
	): int
	{
		$dataType = $current !== null ? $current['dataType'] : '';

		if ($change !== null) {
			$length = $member['subPart'] ?? $change->getEffectiveCharLength();
			if ($length === null) {
				return $this->nonStringIndexBytes($dataType);
			}

			$charset = $change->getTargetCharset();

			return $length * ($charset !== null ? $schema->getCharsetMaxlen($charset) : 4);
		}

		$length = $member['subPart'] ?? ($current !== null ? $current['charLength'] : null);
		if ($length === null) {
			return $this->nonStringIndexBytes($dataType);
		}

		$charset = $current !== null ? $current['charset'] : null;

		return $length * ($charset !== null ? $schema->getCharsetMaxlen($charset) : 4);
	}

	private function indexTooLongViolation(ColumnTargetChange $change, string $indexName): Violation
	{
		$source = new TableViolationSource($change->getDatabase(), null, $change->getTable());

		return new Violation(
			'change.index_too_long',
			'Column [' . $change->getTable() . '][' . $change->getColumn() . '] cannot migrate — index '
			. $indexName . ' would exceed the 3072-byte key length limit after conversion and was left unchanged.',
			$source,
		);
	}

	/**
	 * @param list<ChangeRequest> $surviving
	 */
	private function alignForeignKeyEndpoints(
		array &$surviving,
		?ForeignKeyGraph $graph,
		?SchemaContext $schema
	): void
	{
		if ($graph === null) {
			return;
		}

		/** @var array<string, ColumnTargetChange> $changeByColumn */
		$changeByColumn = [];
		foreach ($surviving as $change) {
			if ($change instanceof ColumnTargetChange) {
				$changeByColumn[$change->getTable() . "\0" . $change->getColumn()] = $change;
			}
		}

		do {
			$changed = false;
			foreach ($graph->getConstraints() as $fk) {
				foreach ($fk->columns as $i => $childColumn) {
					$child = $changeByColumn[$fk->table . "\0" . $childColumn] ?? null;
					$parent = $changeByColumn[$fk->referencedTable . "\0" . $fk->referencedColumns[$i]] ?? null;
					// Align only pairs where both endpoints carry a change; the reconcile pass owns FKs whose
					// other side is out of scope.
					if ($child === null || $parent === null) {
						continue;
					}

					$parentCharset = $parent->getCharset();
					$parentCollation = $parent->getCollation();
					$childCharset = $child->getCharset();
					if ($parentCharset !== null && $parentCollation !== null) {
						if ($childCharset === $parentCharset) {
							// Same charset: only the collation may differ, and realigning it re-encodes nothing.
							if ($child->getCollation() !== $parentCollation) {
								$child->setCharsetCollation($parentCharset, $parentCollation);
								$changed = true;
							}
						} elseif (
							$childCharset !== null
							&& $schema !== null
							&& $this->charsetAlignNonNarrowing($schema, $childCharset, $parentCharset)
							&& !LegacyCharset::isSingleByte($childCharset, $schema->getCharsetMaxlen($childCharset))
						) {
							// Charset change: never force a single-byte legacy charset (latin1 etc.) to a wider one
							// here — that needs the collation auditor's LegacyCharsetConversion gate + binary
							// two-step, which this blind re-encode would skip, corrupting double-encoded data.
							$child->setCharsetCollation($parentCharset, $parentCollation);
							$changed = true;
						}
					}

					$childLength = $child->getEffectiveCharLength();
					$parentLength = $parent->getEffectiveCharLength();
					if (
						$childLength !== null
						&& $parentLength !== null
						&& $parentLength > $childLength
						&& $this->isCharVarchar($child->getType())
						&& $this->isCharVarchar($parent->getType())
					) {
						$child->setType($this->withCharLength($child->getType(), $parentLength))
							->setCharLength($parentLength);
						$changed = true;
					}
				}
			}
		} while ($changed);
	}

	private function charsetAlignNonNarrowing(
		?SchemaContext $schema,
		?string $childCharset,
		string $parentCharset
	): bool
	{
		// A missing schema or unknown child charset is treated as unsafe so a real charset is never narrowed here;
		// aligning a child down to a lower-MAXLEN parent would truncate its multibyte data.
		if ($schema === null || $childCharset === null) {
			return false;
		}

		// `<=`, not `<`: the collation path converges both endpoints to utf8mb4, so an equal-MAXLEN align must still
		// fire — tightening to `<` breaks that byte-identity. No auditor emits endpoints on two different equal-MAXLEN
		// charsets, so this never transcodes across a lossy same-width pair (e.g. latin1↔latin2).
		return $schema->getCharsetMaxlen($childCharset) <= $schema->getCharsetMaxlen($parentCharset);
	}

	private function isCharVarchar(string $type): bool
	{
		return preg_match('#^(?:var)?char\s*\(#', strtolower($type)) === 1;
	}

	private function withCharLength(string $type, int $length): string
	{
		$paren = strpos($type, '(');
		$base = $paren !== false ? substr($type, 0, $paren) : $type;

		return $base . '(' . $length . ')';
	}

	/**
	 * Holds back any foreign key whose string endpoints would not all converge to one charset/collation after the
	 * surviving column conversions, so a rebuilt FK is never charset/type mismatched (the engine's ERROR 3780). An
	 * endpoint's effective charset/collation is its surviving {@see ColumnTargetChange}'s target when it has one,
	 * else its current value from the schema; an endpoint "converts" only when that target differs from current (so
	 * a NOT NULL change restating the same charset is not a conversion). For an FK with a converting endpoint whose
	 * endpoints disagree, every converting endpoint's change is dropped — reverting both sides to a consistent
	 * current state — and the FK reported once. Dropping one FK's endpoints can leave a partner FK mixed, so the
	 * pass iterates to a fixpoint.
	 *
	 * @param list<ChangeRequest> $surviving
	 * @param list<Violation>     $refusals
	 */
	private function reconcileForeignKeyEndpoints(
		array &$surviving,
		int &$fixCount,
		array &$refusals,
		?ForeignKeyGraph $graph,
		?SchemaContext $schema
	): void
	{
		if ($graph === null || $schema === null) {
			return;
		}

		$droppedKeys = [];
		$handled = [];
		do {
			$changed = false;

			/** @var array<string, ColumnTargetChange> $changeByColumn */
			$changeByColumn = [];
			foreach ($surviving as $change) {
				if (!($change instanceof ColumnTargetChange)) {
					continue;
				}

				$colKey = $change->getTable() . "\0" . $change->getColumn();
				if (!isset($droppedKeys[$colKey])) {
					$changeByColumn[$colKey] = $change;
				}
			}

			foreach ($graph->getConstraints() as $fk) {
				$fkKey = $fk->table . "\0" . $fk->name;
				if (isset($handled[$fkKey])) {
					continue;
				}

				$endpoints = [];
				foreach ($fk->columns as $column) {
					$endpoints[] = [$fk->table, $column];
				}

				foreach ($fk->referencedColumns as $column) {
					$endpoints[] = [$fk->referencedTable, $column];
				}

				$effective = [];
				$convertingKeys = [];
				$db = null;
				foreach ($endpoints as $endpoint) {
					$colKey = $endpoint[0] . "\0" . $endpoint[1];
					$change = $changeByColumn[$colKey] ?? null;
					$current = $this->currentCharsetCollation($schema, $endpoint[0], $endpoint[1]);

					if ($change === null) {
						$effective[] = (string) $current[0] . "\1" . (string) $current[1];

						continue;
					}

					$effective[] = (string) $change->getCharset() . "\1" . (string) $change->getCollation();
					$db = $change->getDatabase();
					if ($change->getCharset() !== $current[0] || $change->getCollation() !== $current[1]) {
						$convertingKeys[] = $colKey;
					}
				}

				if ($convertingKeys === [] || $this->allSame($effective)) {
					continue;
				}

				$handled[$fkKey] = true;
				$changed = true;
				foreach ($convertingKeys as $colKey) {
					$droppedKeys[$colKey] = true;
				}

				$refusals[] = $this->foreignKeyInconsistentViolation($db ?? $fk->table, $fk);
			}
		} while ($changed);

		if ($droppedKeys === []) {
			return;
		}

		$kept = [];
		foreach ($surviving as $change) {
			if (
				$change instanceof ColumnTargetChange
				&& isset($droppedKeys[$change->getTable() . "\0" . $change->getColumn()])
			) {
				$fixCount--;

				continue;
			}

			$kept[] = $change;
		}

		$surviving = $kept;
	}

	/**
	 * @return array{0: string|null, 1: string|null}
	 */
	private function currentCharsetCollation(SchemaContext $schema, string $table, string $column): array
	{
		$tableSchema = $schema->getTable($table);
		if ($tableSchema === null) {
			return [null, null];
		}

		$col = $tableSchema->getColumns()[$column] ?? null;
		if ($col === null) {
			return [null, null];
		}

		return [$col['charset'], $col['collation']];
	}

	/**
	 * @param list<string> $values
	 */
	private function allSame(array $values): bool
	{
		foreach ($values as $value) {
			if ($value !== $values[0]) {
				return false;
			}
		}

		return true;
	}

	private function foreignKeyInconsistentViolation(string $dbName, ForeignKeyConstraint $fk): Violation
	{
		$source = new TableViolationSource($dbName, null, $fk->table);

		return new Violation(
			'change.foreign_key_inconsistent',
			'Foreign key ' . $fk->name . ' left unconverted: its string endpoints would not all converge to the'
			. ' same charset/collation, so both sides are kept as-is to preserve referential integrity.',
			$source,
		);
	}

	/**
	 * @param array<string, list<ColumnTargetChange>> $prefixAlters
	 * @param list<ChangeRequest>                      $surviving
	 * @return array<string, list<ColumnTargetChange>>
	 */
	private function filterPrefixAlters(array $prefixAlters, array $surviving): array
	{
		$survivingColumns = [];
		foreach ($surviving as $change) {
			if ($change instanceof ColumnTargetChange) {
				$key = $change->getDatabase() . "\0" . $change->getTable() . "\0" . $change->getColumn();
				$survivingColumns[$key] = true;
			}
		}

		$filtered = [];
		foreach ($prefixAlters as $key => $changes) {
			$kept = [];
			foreach ($changes as $change) {
				$colKey = $change->getDatabase() . "\0" . $change->getTable() . "\0" . $change->getColumn();
				if (isset($survivingColumns[$colKey])) {
					$kept[] = $change;
				}
			}

			if ($kept !== []) {
				$filtered[$key] = $kept;
			}
		}

		return $filtered;
	}

	/**
	 * A conservative upper estimate of a non-string index member's stored key length, used only to keep the
	 * whole-key 3072-byte total from being under-counted; over-estimating merely makes the refusal check more
	 * cautious. Unknown types fall back to 30 bytes (the widest fixed-width member).
	 */
	private function nonStringIndexBytes(string $dataType): int
	{
		$map = [
			'tinyint' => 1,
			'bool' => 1,
			'boolean' => 1,
			'year' => 1,
			'smallint' => 2,
			'mediumint' => 3,
			'date' => 3,
			'int' => 4,
			'integer' => 4,
			'float' => 4,
			'time' => 7,
			'timestamp' => 7,
			'bigint' => 8,
			'double' => 8,
			'datetime' => 8,
			'bit' => 8,
			'decimal' => 30,
			'numeric' => 30,
		];

		return $map[$dataType] ?? 30;
	}

	/**
	 * Every foreign key whose referencing or referenced column is altered must be dropped before the table changes and
	 * re-added afterwards, so the column type change is not blocked by the constraint.
	 *
	 * @param list<ChangeRequest> $changes
	 * @return list<ForeignKeyConstraint>
	 */
	private function foreignKeysToRebuild(array $changes, ?ForeignKeyGraph $graph): array
	{
		if ($graph === null) {
			return [];
		}

		$changedColumns = [];
		foreach ($changes as $change) {
			if ($change instanceof ColumnTargetChange) {
				$changedColumns[$change->getTable() . "\0" . $change->getColumn()] = true;
			}
		}

		if ($changedColumns === []) {
			return [];
		}

		$foreignKeys = [];
		$seen = [];
		foreach ($graph->getConstraints() as $constraint) {
			$touched = false;
			foreach ($constraint->columns as $column) {
				if (isset($changedColumns[$constraint->table . "\0" . $column])) {
					$touched = true;

					break;
				}
			}

			if (!$touched) {
				foreach ($constraint->referencedColumns as $column) {
					if (isset($changedColumns[$constraint->referencedTable . "\0" . $column])) {
						$touched = true;

						break;
					}
				}
			}

			$key = $constraint->table . "\0" . $constraint->name;
			if ($touched && !in_array($key, $seen, true)) {
				$seen[] = $key;
				$foreignKeys[] = $constraint;
			}
		}

		return $foreignKeys;
	}

}

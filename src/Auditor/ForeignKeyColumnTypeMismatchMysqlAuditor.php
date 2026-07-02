<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Change\ColumnTargetChange;
use Orisai\DbAudit\Report\AnalysisResult;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;
use Orisai\DbAudit\Schema\ColumnCharsetClass;
use Orisai\DbAudit\Schema\LegacyCharset;
use Orisai\DbAudit\Schema\SchemaRequest;
use Orisai\DbAudit\Schema\TableExclude;
use function strcmp;
use function strtolower;
use function usort;

final class ForeignKeyColumnTypeMismatchMysqlAuditor extends ForeignKeyColumnTypeMismatchAuditor
{

	private const FixHint = 'Run db-audit:analyse --category=structure --generate-fix=<file> to produce the migration SQL.';

	/**
	 * Every foreign key in the whole database is compared, so all columns (any charset) and the foreign-key
	 * graph (table metadata) are needed; no statistics and no exclude-driven scope expansion.
	 */
	public function getSchemaRequest(): SchemaRequest
	{
		return new SchemaRequest(
			ColumnCharsetClass::any(),
			new TableExclude(),
			true,
			false,
			true,
		);
	}

	public function analyse(): AnalysisResult
	{
		$db = $this->schema->getDatabaseDefault()['name'];
		$columnsByTable = $this->schema->getColumnsByTable();
		$charsetMaxlen = $this->getCharsetMaxlenMap();

		// Each entry carries its (table, column, kind) sort key so the emitted order reproduces the former
		// ORDER BY TABLE_NAME, COLUMN_NAME with the charset violation kept before the type violation per pair.
		$entries = [];
		foreach ($this->schema->getForeignKeyGraph()->getConstraints() as $fk) {
			foreach ($fk->columns as $i => $column) {
				$referencedColumn = $fk->referencedColumns[$i];

				$source = $this->findColumn($columnsByTable, $fk->table, $column);
				$referenced = $this->findColumn($columnsByTable, $fk->referencedTable, $referencedColumn);

				// Mirrors the former INNER JOIN to COLUMNS: a pair whose referencing or referenced column is
				// absent (e.g. the referenced table does not exist) is excluded — that is the referenced-column
				// existence auditor's concern.
				if ($source === null || $referenced === null) {
					continue;
				}

				$charsetDiffers = $source['CHARACTER_SET_NAME'] !== $referenced['CHARACTER_SET_NAME'];
				$sizeDiffers = $source['COLUMN_TYPE'] !== $referenced['COLUMN_TYPE'];

				if (!$charsetDiffers && !$sizeDiffers) {
					continue;
				}

				$childCharset = $source['CHARACTER_SET_NAME'];
				$parentCharset = $referenced['CHARACTER_SET_NAME'];

				// SAFE-ALIGN: the child adopts the parent's charset only when that is lossless, i.e. the child's
				// MAXLEN is strictly narrower (utf8mb3 -> utf8mb4) and the child is not single-byte legacy
				// (latin1/latin2/cp1250 with MAXLEN 1 may hold double-encoded UTF-8, so they stay report-only).
				$charsetSafe = $charsetDiffers
					&& $childCharset !== null
					&& $parentCharset !== null
					&& isset($charsetMaxlen[$childCharset], $charsetMaxlen[$parentCharset])
					&& $charsetMaxlen[$childCharset] < $charsetMaxlen[$parentCharset]
					&& !LegacyCharset::isSingleByte($childCharset, $charsetMaxlen[$childCharset]);

				$childDataType = strtolower($source['DATA_TYPE']);
				$sameStringBase = $childDataType === strtolower($referenced['DATA_TYPE'])
					&& ($childDataType === 'char' || $childDataType === 'varchar');
				$childLength = $source['CHARACTER_MAXIMUM_LENGTH'];
				$parentLength = $referenced['CHARACTER_MAXIMUM_LENGTH'];

				// WIDEN: same char/varchar base and the child is shorter than the parent, so widening the child
				// to the parent length is valid. A child already >= parent is BENIGN (report-only, no fix); a
				// different base type / sign / numeric size is INCOMPATIBLE (widening cannot reconcile it).
				$sizeWiden = $sizeDiffers
					&& $sameStringBase
					&& $childLength !== null
					&& $parentLength !== null
					&& $childLength < $parentLength;
				$sizeIncompatible = $sizeDiffers && !$sameStringBase;

				// A single emitted child change makes the planner drop-and-re-add the FK, so a fix is emitted only
				// when the pair can be made fully valid; an incompatible type or an unsafe charset leaves every
				// mismatch on the pair report-only, so the FK is never rebuilt into a failing re-add.
				$pairFixable = !$sizeIncompatible && !($charsetDiffers && !$charsetSafe);

				$sourceSource = new ColumnViolationSource($db, null, $fk->table, $column);
				$referencedSource = new ColumnViolationSource($db, null, $fk->referencedTable, $referencedColumn);

				if ($charsetDiffers) {
					$changes = [];
					if ($pairFixable) {
						$changes[] = ColumnTargetChange::forColumn($db, $fk->table, $column)
							->setCharsetCollation(
								(string) $parentCharset,
								(string) $referenced['COLLATION_NAME'],
							);
					}

					$entries[] = [
						'table' => $fk->table,
						'column' => $column,
						'kind' => 0,
						'violation' => new Violation(
							'foreign_key.charset_mismatch',
							'Column '
							. $sourceSource->toString()
							. ' references column '
							. $referencedSource->toString()
							. ' but the character set does not match.',
							$sourceSource,
							$changes !== [],
							$changes !== [] ? self::FixHint : null,
							$changes,
						),
					];
				}

				if ($sizeDiffers) {
					$changes = [];
					if ($pairFixable && $sizeWiden) {
						$changes[] = ColumnTargetChange::forColumn($db, $fk->table, $column)
							->setType($childDataType . '(' . $parentLength . ')')
							->setCharLength($parentLength);
					}

					$entries[] = [
						'table' => $fk->table,
						'column' => $column,
						'kind' => 1,
						'violation' => new Violation(
							'foreign_key.size_mismatch',
							'Column '
							. $sourceSource->toString()
							. ' references column '
							. $referencedSource->toString()
							. ' but the column size does not match.',
							$sourceSource,
							$changes !== [],
							$changes !== [] ? self::FixHint : null,
							$changes,
						),
					];
				}
			}
		}

		usort(
			$entries,
			static function (array $a, array $b): int {
				if ($a['table'] !== $b['table']) {
					return strcmp($a['table'], $b['table']);
				}

				if ($a['column'] !== $b['column']) {
					return strcmp($a['column'], $b['column']);
				}

				return $a['kind'] <=> $b['kind'];
			},
		);

		$violations = [];
		foreach ($entries as $entry) {
			$violations[] = $entry['violation'];
		}

		return new AnalysisResult($violations);
	}

	/**
	 * @param array<string, list<array{TABLE_NAME: string, COLUMN_NAME: string, COLUMN_TYPE: string, DATA_TYPE: string, CHARACTER_SET_NAME: string|null, COLLATION_NAME: string|null, IS_NULLABLE: string, COLUMN_DEFAULT: string|null, EXTRA: string, COLUMN_COMMENT: string, GENERATION_EXPRESSION: string|null, CHARACTER_MAXIMUM_LENGTH: int|null, ORDINAL_POSITION: int}>> $columnsByTable
	 * @return array{TABLE_NAME: string, COLUMN_NAME: string, COLUMN_TYPE: string, DATA_TYPE: string, CHARACTER_SET_NAME: string|null, COLLATION_NAME: string|null, IS_NULLABLE: string, COLUMN_DEFAULT: string|null, EXTRA: string, COLUMN_COMMENT: string, GENERATION_EXPRESSION: string|null, CHARACTER_MAXIMUM_LENGTH: int|null, ORDINAL_POSITION: int}|null
	 */
	private function findColumn(array $columnsByTable, string $table, string $column): ?array
	{
		foreach ($columnsByTable[$table] ?? [] as $candidate) {
			if ($candidate['COLUMN_NAME'] === $column) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * @return array<string, int>
	 */
	private function getCharsetMaxlenMap(): array
	{
		$map = [];
		foreach ($this->schema->getCharacterSets() as $row) {
			$map[$row['CHARACTER_SET_NAME']] = $row['MAXLEN'];
		}

		return $map;
	}

}

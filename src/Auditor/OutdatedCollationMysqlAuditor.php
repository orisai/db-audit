<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Change\ColumnTargetChange;
use Orisai\DbAudit\Change\DatabaseDefaultChange;
use Orisai\DbAudit\Change\TableDefaultCollationChange;
use Orisai\DbAudit\Collation\CollationProfile;
use Orisai\DbAudit\Collation\CollationResolver;
use Orisai\DbAudit\Collation\CollationTarget;
use Orisai\DbAudit\Collation\CollationTargetPolicy;
use Orisai\DbAudit\Collation\DatabaseDefaultHandling;
use Orisai\DbAudit\Collation\ExplicitCharsetReferenceScanner;
use Orisai\DbAudit\Collation\LegacyCharsetConversion;
use Orisai\DbAudit\Collation\Plan\ColumnMigration;
use Orisai\DbAudit\Collation\Plan\MigrationPlan;
use Orisai\DbAudit\Collation\Plan\TableMigration;
use Orisai\DbAudit\Driver\ServerInfoReader;
use Orisai\DbAudit\Privilege\Grants;
use Orisai\DbAudit\Privilege\GrantsReader;
use Orisai\DbAudit\Report\Advisory;
use Orisai\DbAudit\Report\AnalysisResult;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\TableViolationSource;
use Orisai\DbAudit\Report\Violation;
use Orisai\DbAudit\Schema\ColumnCharsetClass;
use Orisai\DbAudit\Schema\ForeignKeyConstraint;
use Orisai\DbAudit\Schema\LegacyCharset;
use Orisai\DbAudit\Schema\SchemaRequest;
use Orisai\Exceptions\Logic\InvalidState;
use Throwable;
use function array_keys;
use function array_merge;
use function explode;
use function implode;
use function in_array;
use function sort;
use function strpos;
use function strrpos;
use function strtolower;
use function substr;

final class OutdatedCollationMysqlAuditor extends OutdatedCollationAuditor
{

	/** @var array<string, bool> */
	private array $migratedCharsets = [];

	/** @var array<string, bool> */
	private array $migratedCollations = [];

	/**
	 * Every column of the in-scope tables is needed (any), not just the charset-bearing or non-utf8mb4 ones:
	 * the row-format planning sums an index's post-conversion key length across ALL its member columns —
	 * including an already-utf8mb4 sibling that merely shares the index with a converting column — and the
	 * column-definition reconstruction is likewise cross-column. The config excludes scope the table set,
	 * foreign-key-related tables are pulled in for boundary conversion, and statistics are required for
	 * unique-index and row-format planning.
	 */
	public function getSchemaRequest(): SchemaRequest
	{
		return new SchemaRequest(
			ColumnCharsetClass::any(),
			$this->config->getExcludeTables(),
			true,
			true,
			true,
		);
	}

	public function analyse(): AnalysisResult
	{
		$plan = $this->buildPlan();

		return new AnalysisResult(
			$this->collectViolations($plan),
			$this->collectAdvisories($plan),
		);
	}

	/**
	 * The report proper: the planned-but-skipped problems (index too long even after DYNAMIC, a refused
	 * unique-index conversion, unclassifiable collation) plus every outdated database default, table default
	 * and column the migration changes. Guidance for the operator (charset-reference warnings, latin1
	 * cautions, lock and ALTER DATABASE notes) is carried separately as advisories.
	 *
	 * @return list<Violation>
	 */
	private function collectViolations(MigrationPlan $plan): array
	{
		$dbName = $plan->databaseName;

		$violations = $plan->unfixable;

		if ($plan->databaseDefault !== null) {
			$source = new TableViolationSource($dbName, null, '(database default)');
			$violations[] = new Violation(
				'outdated_collation.database_default',
				'Database ' . $dbName . ' default charset/collation is outdated.',
				$source,
				true,
				'Run db-audit:analyse --category=structure --generate-fix=<file> to produce the migration SQL.',
				[
					new DatabaseDefaultChange(
						$dbName,
						$plan->databaseDefault->charset,
						$plan->databaseDefault->collation,
					),
				],
			);
		}

		foreach ($plan->tables as $table) {
			if ($table->tableDefault !== null) {
				$tableDefaultChange = new TableDefaultCollationChange(
					$dbName,
					$table->table,
					$table->tableDefault->charset,
					$table->tableDefault->collation,
				);
				$source = new TableViolationSource($dbName, null, $table->table);
				$violations[] = new Violation(
					'outdated_collation.table_default',
					'Table ' . $source->toString() . ' default charset/collation is outdated.',
					$source,
					true,
					'Run db-audit:analyse --category=structure --generate-fix=<file> to produce the migration SQL.',
					[$tableDefaultChange],
				);
			}

			foreach ($table->columns as $column) {
				$source = new ColumnViolationSource($dbName, null, $table->table, $column->name);
				$source->setColumnType($column->columnType);

				// A sparse delta carrying only what this auditor decides — the target charset/collation and the
				// (possibly FK-widened) type, plus the latin1 two-step when needed. Nullability, default, comment,
				// on-update and generated come from the current definition via the planner's merge, so this auditor
				// no longer restates (and can no longer drop) them.
				$change = ColumnTargetChange::forColumn($dbName, $table->table, $column->name)
					->setType($column->columnType)
					->setCharsetCollation($column->target->charset, $column->target->collation);
				if ($column->binaryTwoStep) {
					$change->setBinaryTwoStep($this->binaryTypeFor($column->columnType));
				}

				$violations[] = new Violation(
					'outdated_collation.column',
					'Column ' . $source->toString() . ' has an outdated charset/collation.',
					$source,
					true,
					'Run db-audit:analyse --category=structure --generate-fix=<file> to produce the migration SQL.',
					[$change],
				);
			}
		}

		return $violations;
	}

	/**
	 * Operator guidance that is not a finding to be fixed: the standing charset-reference warning and the
	 * per-stored-object reference hits (only when something is actually migrated, so the grep targets are
	 * non-empty), plus the latin1 cautions and the ALTER DATABASE skip notes accumulated while planning
	 * (these surface even when the migration itself is empty, e.g. a latin1-only column left unchanged
	 * under report mode).
	 *
	 * @return list<Advisory>
	 */
	private function collectAdvisories(MigrationPlan $plan): array
	{
		$dbName = $plan->databaseName;

		$advisories = [];

		// The standing charset-reference advisory and the per-object hits only make sense once at least one
		// charset/collation is being migrated (migratedNames() drives both); when nothing migrates they would
		// reference an empty target list, so they are skipped — exactly as before.
		if (!$plan->isEmpty()) {
			$source = new TableViolationSource($dbName, null, '(explicit charset/collation references)');
			$advisories[] = new Advisory($this->buildAdvisoryMessage(), $source);

			foreach ($this->scanStoredObjects() as $hit) {
				$hitSource = new TableViolationSource($dbName, null, $hit['type'] . ' ' . $hit['name']);
				$advisories[] = new Advisory(
					$hit['type'] . ' ' . $hit['name'] . ' references ' . implode(', ', $hit['matches'])
					. ' and may break after the migration; review and update it.',
					$hitSource,
				);
			}
		}

		foreach ($plan->advisories as $advisory) {
			$advisories[] = $advisory;
		}

		return $advisories;
	}

	private function buildPlan(): MigrationPlan
	{
		$this->migratedCharsets = [];
		$this->migratedCollations = [];

		$server = (new ServerInfoReader($this->dbal))->read();
		$available = [];
		foreach ($this->getAvailableCollationRows() as $row) {
			$available[] = $row['COLLATION_NAME'];
		}

		$resolver = new CollationResolver($server, $available);
		$charsetMaxlen = $this->getCharsetMaxlenMap();

		$dbDefault = $this->schema->getDatabaseDefault();
		$exclude = $this->config->getExcludeTables();

		$tables = $this->getTables();
		$includedNames = [];
		foreach ($tables as $table) {
			$name = $table['TABLE_NAME'];
			if (!$exclude->matches($name)) {
				$includedNames[$name] = true;
			}
		}

		// With no table in scope the heavy per-table introspection is skipped entirely, but the
		// database-default planning (cheap — no schema scan) still runs so an all-excluded database keeps
		// emitting its ALTER DATABASE / DB-default planning exactly as before.
		if ($includedNames === []) {
			$advisories = [];
			$databaseDefault = $this->planDatabaseDefault($dbDefault, $resolver, $advisories);

			return new MigrationPlan($databaseDefault, $dbDefault['name'], [], [], $advisories);
		}

		$columnsByTable = $this->getColumnsByTable($includedNames);

		$uniqueIndexedColumns = $this->uniqueIndexedColumns($this->getUniqueIndexColumns($includedNames));

		$tableMigrations = [];
		$unfixable = [];
		$advisories = [];

		foreach ($tables as $table) {
			$name = $table['TABLE_NAME'];
			if ($exclude->matches($name)) {
				continue;
			}

			$columnMigrations = [];
			$columnsByName = [];
			foreach ($columnsByTable[$name] ?? [] as $column) {
				$underUniqueIndex = isset($uniqueIndexedColumns[$name][$column['COLUMN_NAME']]);
				$migration = $this->planColumn(
					$dbDefault['name'],
					$name,
					$column,
					$resolver,
					$underUniqueIndex,
					$charsetMaxlen,
					$unfixable,
					$advisories,
				);
				if ($migration !== null) {
					$columnMigrations[] = $migration;
					$columnsByName[$column['COLUMN_NAME']] = $column;
				}
			}

			$columnMigrations = $this->resolveUniqueIndexCollisions(
				$dbDefault['name'],
				$name,
				$columnMigrations,
				$columnsByName,
				$unfixable,
			);

			$tableDefault = $this->planTableDefault(
				$table,
				$resolver,
				$dbDefault['name'],
				$charsetMaxlen,
				$unfixable,
				$advisories,
			);

			// The planner's FK-endpoint alignment can widen a child FK column; its key-length refusal and
			// FK-endpoint consistency then run post-ignore over the structured changes these targets become.
			$tableMigrations[$name] = new TableMigration(
				$name,
				$tableDefault,
				$columnMigrations,
			);
		}

		$foreignKeys = $this->schema->getForeignKeyGraph()->getTouching($includedNames);
		$this->applyForeignKeyBoundary(
			$dbDefault['name'],
			$includedNames,
			$foreignKeys,
			$resolver,
			$charsetMaxlen,
			$tableMigrations,
		);

		$tableMigrations = $this->collectTableMigrations($tableMigrations);

		$databaseDefault = $this->planDatabaseDefault($dbDefault, $resolver, $advisories);

		return new MigrationPlan($databaseDefault, $dbDefault['name'], $tableMigrations, $unfixable, $advisories);
	}

	/**
	 * Excluding a table means "do not migrate that table generally", but a foreign-key relationship stays
	 * in scope unless BOTH of its tables are excluded. When exactly one endpoint is excluded the FK column
	 * on the EXCLUDED side is still pulled through the normal per-column conversion decision so both ends
	 * move to the same target and the constraint stays valid. The excluded table's other columns and its
	 * table default remain untouched: only its participating FK column(s) get a minimal MODIFY.
	 *
	 * An FK's two columns always share the same charset/collation (the engine requires it), so they resolve
	 * to the same target and get the same treatment — both convert (utf8mb3/multibyte legacy) or neither
	 * does (single-byte legacy in report mode), so a boundary FK never produces a charset mismatch.
	 *
	 * @param array<string, bool> $includedNames
	 * @param list<ForeignKeyConstraint> $foreignKeys
	 * @param array<string, int> $charsetMaxlen
	 * @param array<string, TableMigration> $tableMigrations
	 */
	private function applyForeignKeyBoundary(
		string $dbName,
		array $includedNames,
		array $foreignKeys,
		CollationResolver $resolver,
		array $charsetMaxlen,
		array &$tableMigrations
	): void
	{
		// Both endpoints of a boundary FK can sit on the same excluded table, and an excluded table may
		// take part in more than one boundary FK, so the participating columns are accumulated per excluded
		// table before any TableMigration is built.
		$excludedFkColumns = [];
		foreach ($foreignKeys as $fk) {
			$childExcluded = !isset($includedNames[$fk->table]);
			$parentExcluded = !isset($includedNames[$fk->referencedTable]);

			if ($childExcluded) {
				foreach ($fk->columns as $column) {
					$excludedFkColumns[$fk->table][$column] = true;
				}
			}

			if ($parentExcluded) {
				foreach ($fk->referencedColumns as $column) {
					$excludedFkColumns[$fk->referencedTable][$column] = true;
				}
			}
		}

		$columnsByTable = $this->schema->getColumnsByTable();
		foreach ($excludedFkColumns as $table => $columnNames) {
			$columnMigrations = [];
			foreach ($columnsByTable[$table] ?? [] as $column) {
				if (!isset($columnNames[$column['COLUMN_NAME']]) || $column['CHARACTER_SET_NAME'] === null) {
					continue;
				}

				// The excluded-side FK column runs through the same per-column decision as the in-scope side,
				// but never under unique-index handling — only its FK MODIFY is wanted, nothing else. The
				// excluded table is otherwise out of scope, so any violation or advisory it raises (e.g. a
				// single-byte legacy column in report mode that simply does not convert) is discarded, not
				// reported; only the in-scope side surfaces such a report.
				$excludedUnfixable = [];
				$excludedAdvisories = [];
				$migration = $this->planColumn(
					$dbName,
					$table,
					$column,
					$resolver,
					false,
					$charsetMaxlen,
					$excludedUnfixable,
					$excludedAdvisories,
				);
				if ($migration !== null) {
					$columnMigrations[] = $migration;
				}
			}

			if ($columnMigrations === []) {
				continue;
			}

			$tableMigrations[$table] = new TableMigration(
				$table,
				null,
				$columnMigrations,
			);
		}
	}

	/**
	 * @param array<string, TableMigration> $tableMigrations
	 * @return list<TableMigration>
	 */
	private function collectTableMigrations(array $tableMigrations): array
	{
		$collected = [];
		foreach ($tableMigrations as $migration) {
			if (
				$migration->columns !== []
				|| $migration->tableDefault !== null
			) {
				$collected[] = $migration;
			}
		}

		return $collected;
	}

	/**
	 * Flattens the unique-index statistics (INFORMATION_SCHEMA reports a PRIMARY KEY as a unique index too)
	 * into a per-table set of the columns that participate in any unique index — the only fact the
	 * deterministic order-preserving rule needs to mark a converting column as under a unique index.
	 *
	 * @param list<array{TABLE_NAME: string, COLUMN_NAME: string}> $rows
	 * @return array<string, array<string, bool>>
	 */
	private function uniqueIndexedColumns(array $rows): array
	{
		$byTable = [];
		foreach ($rows as $row) {
			$byTable[$row['TABLE_NAME']][$row['COLUMN_NAME']] = true;
		}

		return $byTable;
	}

	/**
	 * @param array{
	 *     TABLE_NAME: string, COLUMN_NAME: string, COLUMN_TYPE: string, DATA_TYPE: string,
	 *     CHARACTER_SET_NAME: string|null, COLLATION_NAME: string|null,
	 *     IS_NULLABLE: string, COLUMN_DEFAULT: string|null, EXTRA: string, COLUMN_COMMENT: string,
	 *     GENERATION_EXPRESSION: string|null, CHARACTER_MAXIMUM_LENGTH: int|null, ORDINAL_POSITION: int
	 * } $column
	 * @param array<string, int> $charsetMaxlen
	 * @param list<Violation> $unfixable
	 * @param list<Advisory>  $advisories
	 */
	private function planColumn(
		string $dbName,
		string $table,
		array $column,
		CollationResolver $resolver,
		bool $underUniqueIndex,
		array $charsetMaxlen,
		array &$unfixable,
		array &$advisories
	): ?ColumnMigration
	{
		$charset = (string) $column['CHARACTER_SET_NAME'];
		$collation = (string) $column['COLLATION_NAME'];

		if (!$this->shouldConvertCharset($charset)) {
			return null;
		}

		$profile = CollationProfile::fromCollationName($collation);
		if ($profile === null) {
			$source = new ColumnViolationSource(
				$dbName,
				null,
				$table,
				$column['COLUMN_NAME'],
			);
			$source->setColumnType($column['COLUMN_TYPE']);
			$unfixable[] = new Violation(
				'outdated_collation.unclassifiable_collation',
				'Column ' . $source->toString() . ' uses an unclassifiable collation ' . $collation . ' and was left unchanged.',
				$source,
			);

			return null;
		}

		$target = $resolver->resolve($charset, $collation, $profile, $this->config->getTargetPolicy());
		if ($target === null) {
			return null;
		}

		if ($target->collation === $collation && $charset === 'utf8mb4') {
			return null;
		}

		$binaryTwoStep = false;
		if (LegacyCharset::isSingleByte($charset, $charsetMaxlen[$charset] ?? 1)) {
			$mode = $this->config->getLegacyCharsetConversion();

			// The two-step (VARBINARY → utf8mb4) is only meaningful for plain stored string types that
			// binaryTypeFor() can map. Generated columns recompute their value and ENUM/SET enumerate it,
			// so neither carries raw bytes to reinterpret — under assume-double-encoded those are reported
			// unfixable rather than silently mangled.
			$isGenerated = $column['GENERATION_EXPRESSION'] !== null && $column['GENERATION_EXPRESSION'] !== '';
			$isMappable = in_array(
				strtolower($column['DATA_TYPE']),
				['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext'],
				true,
			);
			$isPlainText = $isMappable && !$isGenerated;

			if ($mode === LegacyCharsetConversion::report()) {
				$unfixable[] = $this->legacyCharsetReportViolation($dbName, $table, $column, $charset);

				return null;
			}

			if ($mode === LegacyCharsetConversion::assumeDoubleEncoded()) {
				if (!$isPlainText) {
					$unfixable[] = $this->legacyCharsetUnreinterpretableViolation(
						$dbName,
						$table,
						$column,
						$charset,
					);

					return null;
				}

				$binaryTwoStep = true;
			}
		}

		// The unique-index decision is deferred to resolveUniqueIndexCollisions(), which applies the
		// deterministic order-preserving rule over the resolved target. Here a converting column merely
		// records that it participates in a unique index whose collation is changing.
		$underUniqueIndex = $underUniqueIndex && $target->collation !== $collation;

		$this->recordMigratedSource($charset, $collation);

		// Only the char/varchar length is kept (FK alignment widens on it; the planner reads charLength off the
		// effective type). The rest of the current definition now lives on the read side (SchemaContext) and is
		// merged in by the planner, so the auditor no longer normalizes the whole column here.
		$dataType = strtolower($column['DATA_TYPE']);
		$charLength = $dataType === 'char' || $dataType === 'varchar'
			? $column['CHARACTER_MAXIMUM_LENGTH']
			: null;

		return new ColumnMigration(
			$column['COLUMN_NAME'],
			$column['COLUMN_TYPE'],
			$target,
			$underUniqueIndex,
			$binaryTwoStep,
			$charLength,
		);
	}

	/**
	 * Decides, deterministically and without reading any row, whether a converting column under a unique
	 * index may convert.
	 *
	 * An order-preserving conversion (utf8mb3 -> its utf8mb4 namesake under the default preserveOrder policy:
	 * same bytes, same collation algorithm) can never turn two previously-distinct keys into one, so it
	 * always converts. A sensitivity-changing target (e.g. a modernize *_ci) could equate keys that the
	 * source kept distinct, so it is refused unless the operator opts in via forceUniqueIndexConversion. The
	 * data-dependent question of whether a non-order-preserving conversion actually collides belongs to the
	 * Stage 5 data auditor, not to this schema-only structure path.
	 *
	 * @param list<ColumnMigration> $columnMigrations
	 * @param array<string, array{
	 *     TABLE_NAME: string, COLUMN_NAME: string, COLUMN_TYPE: string, DATA_TYPE: string,
	 *     CHARACTER_SET_NAME: string|null, COLLATION_NAME: string|null,
	 *     IS_NULLABLE: string, COLUMN_DEFAULT: string|null, EXTRA: string, COLUMN_COMMENT: string,
	 *     GENERATION_EXPRESSION: string|null, CHARACTER_MAXIMUM_LENGTH: int|null, ORDINAL_POSITION: int
	 * }> $columnsByName
	 * @param list<Violation> $unfixable
	 * @return list<ColumnMigration>
	 */
	private function resolveUniqueIndexCollisions(
		string $dbName,
		string $table,
		array $columnMigrations,
		array $columnsByName,
		array &$unfixable
	): array
	{
		$kept = [];
		foreach ($columnMigrations as $migration) {
			if (
				!$migration->underUniqueIndex
				|| $migration->target->orderPreserving
				|| $this->config->forcesUniqueIndexConversion()
			) {
				$kept[] = $migration;

				continue;
			}

			$unfixable[] = $this->uniqueIndexViolation(
				$dbName,
				$table,
				$columnsByName[$migration->name],
				'cannot migrate — converting it under a unique index to the sensitivity-changing collation '
				. $migration->target->collation . ' could create duplicate keys; run'
				. ' UniqueIndexCollationCollisionMysqlAuditor (data audit) to check whether the data actually'
				. ' collides, then set forceUniqueIndexConversion to convert anyway.',
			);
		}

		return $kept;
	}

	/**
	 * @param array{
	 *     TABLE_NAME: string, COLUMN_NAME: string, COLUMN_TYPE: string, DATA_TYPE: string,
	 *     CHARACTER_SET_NAME: string|null, COLLATION_NAME: string|null,
	 *     IS_NULLABLE: string, COLUMN_DEFAULT: string|null, EXTRA: string, COLUMN_COMMENT: string,
	 *     GENERATION_EXPRESSION: string|null, CHARACTER_MAXIMUM_LENGTH: int|null, ORDINAL_POSITION: int
	 * } $column
	 */
	private function legacyCharsetReportViolation(
		string $dbName,
		string $table,
		array $column,
		string $charset
	): Violation
	{
		$source = new ColumnViolationSource($dbName, null, $table, $column['COLUMN_NAME']);
		$source->setColumnType($column['COLUMN_TYPE']);

		return new Violation(
			'outdated_collation.legacy_charset',
			'Column ' . $source->toString() . ' uses legacy charset \'' . $charset . '\' which may be genuine'
			. ' or double-encoded UTF-8; choose a conversion mode (assume-genuine or assume-double-encoded)'
			. ' after auditing the data — run the \'latin1_encoding\' audit (Latin1EncodingMysqlAuditor) on the'
			. ' data to determine which — left unchanged.',
			$source,
		);
	}

	/**
	 * @param array{
	 *     TABLE_NAME: string, COLUMN_NAME: string, COLUMN_TYPE: string, DATA_TYPE: string,
	 *     CHARACTER_SET_NAME: string|null, COLLATION_NAME: string|null,
	 *     IS_NULLABLE: string, COLUMN_DEFAULT: string|null, EXTRA: string, COLUMN_COMMENT: string,
	 *     GENERATION_EXPRESSION: string|null, CHARACTER_MAXIMUM_LENGTH: int|null, ORDINAL_POSITION: int
	 * } $column
	 */
	private function legacyCharsetUnreinterpretableViolation(
		string $dbName,
		string $table,
		array $column,
		string $charset
	): Violation
	{
		$source = new ColumnViolationSource($dbName, null, $table, $column['COLUMN_NAME']);
		$source->setColumnType($column['COLUMN_TYPE']);

		return new Violation(
			'outdated_collation.legacy_charset_uncoercible',
			'Column ' . $source->toString() . ' uses legacy charset \'' . $charset . '\' but its value is'
			. ' enumerated or recomputed (ENUM/SET or generated), so the byte-reinterpreting VARBINARY'
			. ' two-step of assume-double-encoded cannot apply — left unchanged.',
			$source,
		);
	}

	/**
	 * @param array{
	 *     TABLE_NAME: string, COLUMN_NAME: string, COLUMN_TYPE: string, DATA_TYPE: string,
	 *     CHARACTER_SET_NAME: string|null, COLLATION_NAME: string|null,
	 *     IS_NULLABLE: string, COLUMN_DEFAULT: string|null, EXTRA: string, COLUMN_COMMENT: string,
	 *     GENERATION_EXPRESSION: string|null, CHARACTER_MAXIMUM_LENGTH: int|null, ORDINAL_POSITION: int
	 * } $column
	 */
	private function uniqueIndexViolation(string $dbName, string $table, array $column, string $reason): Violation
	{
		$source = new ColumnViolationSource($dbName, null, $table, $column['COLUMN_NAME']);
		$source->setColumnType($column['COLUMN_TYPE']);

		return new Violation(
			'outdated_collation.unique_index',
			'Column ' . $source->toString() . ' ' . $reason,
			$source,
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

		// A legacy charset always enters planColumn(): a single-byte one (latin1 family) is then routed
		// through getLegacyCharsetConversion() (report unfixable, straight convert, or VARBINARY two-step),
		// while a multibyte one (gbk/big5/sjis) converts straight to utf8mb4 by default.
		return true;
	}

	/**
	 * @param array{TABLE_NAME: string, ENGINE: string|null, ROW_FORMAT: string|null, TABLE_COLLATION: string|null} $table
	 * @param array<string, int> $charsetMaxlen
	 * @param list<Violation> $unfixable
	 * @param list<Advisory>  $advisories
	 */
	private function planTableDefault(
		array $table,
		CollationResolver $resolver,
		string $dbName,
		array $charsetMaxlen,
		array &$unfixable,
		array &$advisories
	): ?CollationTarget
	{
		$collation = $table['TABLE_COLLATION'];
		if ($collation === null) {
			return null;
		}

		$charset = explode('_', $collation)[0];
		if (!$this->shouldConvertCharset($charset)) {
			return null;
		}

		$profile = CollationProfile::fromCollationName($collation);
		if ($profile === null) {
			return null;
		}

		$target = $resolver->resolve($charset, $collation, $profile, $this->config->getTargetPolicy());
		if ($target === null) {
			return null;
		}

		if ($target->collation === $collation && $charset === 'utf8mb4') {
			return null;
		}

		if (LegacyCharset::isSingleByte($charset, $charsetMaxlen[$charset] ?? 1)) {
			$mode = $this->config->getLegacyCharsetConversion();

			if ($mode === LegacyCharsetConversion::report()) {
				$source = new TableViolationSource($dbName, null, $table['TABLE_NAME']);
				$unfixable[] = new Violation(
					'outdated_collation.legacy_charset',
					'Table ' . $source->toString() . ' default uses legacy charset \'' . $charset . '\' which may be genuine'
					. ' or double-encoded UTF-8; choose a conversion mode (assume-genuine or assume-double-encoded)'
					. ' after auditing the data — run the \'latin1_encoding\' audit (Latin1EncodingMysqlAuditor) on the'
					. ' data to determine which — left unchanged.',
					$source,
				);

				return null;
			}
		}

		$this->recordMigratedSource($charset, $collation);

		return $target;
	}

	/**
	 * @param array{name: string, charset: string, collation: string} $dbDefault
	 * @param list<Advisory> $advisories
	 */
	private function planDatabaseDefault(
		array $dbDefault,
		CollationResolver $resolver,
		array &$advisories
	): ?CollationTarget
	{
		$charset = $dbDefault['charset'];
		$policy = $this->config->getTargetPolicy();
		if ($charset === 'utf8mb4' && $policy === CollationTargetPolicy::preserveOrder()) {
			return null;
		}

		$collation = $dbDefault['collation'];
		$profile = CollationProfile::fromCollationName($collation);
		if ($profile === null) {
			return null;
		}

		$target = $resolver->resolve($charset, $collation, $profile, $policy);
		if ($target === null) {
			return null;
		}

		if ($target->collation === $collation && $charset === 'utf8mb4') {
			return null;
		}

		if ($this->config->getDatabaseDefault() === DatabaseDefaultHandling::skip()) {
			$advisories[] = $this->databaseDefaultAdvisory(
				$dbDefault['name'],
				'ALTER DATABASE skipped by configuration.',
			);

			return null;
		}

		$grants = $this->readExecutionGrants();
		if ($grants === null) {
			$advisories[] = $this->databaseDefaultAdvisory(
				$dbDefault['name'],
				'ALTER DATABASE skipped: could not verify ALTER privilege.',
			);

			return null;
		}

		if (!$grants->has('ALTER', $dbDefault['name'])) {
			$advisories[] = $this->databaseDefaultAdvisory(
				$dbDefault['name'],
				'ALTER DATABASE skipped: missing ALTER privilege on ' . $dbDefault['name'] . '.',
			);

			return null;
		}

		$this->recordMigratedSource($charset, $collation);

		return $target;
	}

	private function readExecutionGrants(): ?Grants
	{
		$reader = new GrantsReader($this->dbal);
		$account = $this->config->getExecutionAccount();

		try {
			if ($account === null) {
				return $reader->readForCurrentUser();
			}

			$lastAt = strrpos($account, '@');
			if ($lastAt === false) {
				return $reader->readForAccount($account);
			}

			$user = substr($account, 0, $lastAt);
			$host = substr($account, $lastAt + 1);

			return $reader->readForAccount($user, $host);
		} catch (Throwable $e) {
			return null;
		}
	}

	private function databaseDefaultAdvisory(string $dbName, string $message): Advisory
	{
		$source = new TableViolationSource($dbName, null, '(database default)');

		return new Advisory($message, $source);
	}

	private function recordMigratedSource(string $charset, string $collation): void
	{
		if ($charset !== '') {
			$this->migratedCharsets[$charset] = true;
		}

		if ($collation !== '') {
			$this->migratedCollations[$collation] = true;
		}
	}

	/**
	 * The source charset/collation names actually being migrated, deduplicated and sorted, so the operator
	 * knows exactly which tokens to grep the application codebase for.
	 *
	 * @return list<string>
	 */
	private function migratedNames(): array
	{
		$names = array_keys(array_merge($this->migratedCharsets, $this->migratedCollations));
		sort($names);

		return $names;
	}

	/**
	 * Explicit references to the OLD charset/collation that a charset conversion can break and that must be
	 * searched for; listed in both the report advisory and the generated-script comment header.
	 *
	 * @return list<string>
	 */
	private function advisoryPatterns(): array
	{
		return [
			'COLLATE <name>',
			'CONVERT(... USING <name>)',
			"_<name>'...' (charset introducer)",
			'CHARACTER SET <name>',
			'CAST(... AS ... CHARACTER SET <name>)',
		];
	}

	private function buildAdvisoryMessage(): string
	{
		return 'This migration changes charsets/collations. Explicit references to the old'
			. ' charset/collation can break afterwards (e.g. COLLATE on a now-utf8mb4 column). Search the'
			. ' application codebase for the patterns ' . implode(', ', $this->advisoryPatterns())
			. ' referencing any of: ' . implode(', ', $this->migratedNames())
			. '. This tool cannot inspect application code, so the codebase must be checked manually.';
	}

	/**
	 * Scans schema-stored SQL (views, routines, triggers, events and generated-column expressions) for
	 * explicit references to the migrated charset/collation names. It is a best-effort heuristic — the
	 * authoritative check is the manual codebase search the advisory instructs — so it favours recall.
	 *
	 * @return list<array{type: string, name: string, matches: list<string>}>
	 */
	private function scanStoredObjects(): array
	{
		$names = $this->migratedNames();
		if ($names === []) {
			return [];
		}

		$scanner = new ExplicitCharsetReferenceScanner();
		$hits = [];

		foreach ($this->collectStoredDefinitions() as $object) {
			$matches = $scanner->scan($object['definition'], $names);
			if ($matches !== []) {
				$hits[] = [
					'type' => $object['type'],
					'name' => $object['name'],
					'matches' => $matches,
				];
			}
		}

		return $hits;
	}

	/**
	 * @return list<array{type: string, name: string, definition: string}>
	 */
	private function collectStoredDefinitions(): array
	{
		$objects = [];

		foreach ($this->schema->getViews() as $view) {
			$objects[] = ['type' => 'VIEW', 'name' => $view['TABLE_NAME'], 'definition' => $view['VIEW_DEFINITION']];
		}

		foreach ($this->schema->getRoutines() as $routine) {
			$objects[] = [
				'type' => $routine['ROUTINE_TYPE'],
				'name' => $routine['ROUTINE_NAME'],
				'definition' => $routine['ROUTINE_DEFINITION'],
			];
		}

		foreach ($this->schema->getTriggers() as $trigger) {
			$objects[] = [
				'type' => 'TRIGGER',
				'name' => $trigger['TRIGGER_NAME'],
				'definition' => $trigger['ACTION_STATEMENT'],
			];
		}

		foreach ($this->schema->getEvents() as $event) {
			$objects[] = [
				'type' => 'EVENT',
				'name' => $event['EVENT_NAME'],
				'definition' => $event['EVENT_DEFINITION'],
			];
		}

		foreach ($this->schema->getColumnsByTable() as $table => $columns) {
			foreach ($columns as $column) {
				$generation = $column['GENERATION_EXPRESSION'];
				if ($generation !== null && $generation !== '') {
					$objects[] = [
						'type' => 'GENERATED COLUMN',
						'name' => $table . '.' . $column['COLUMN_NAME'],
						'definition' => $generation,
					];
				}
			}
		}

		return $objects;
	}

	private function binaryTypeFor(string $columnType): string
	{
		$base = strtolower($columnType);
		$paren = strpos($base, '(');
		$name = $paren !== false ? substr($base, 0, $paren) : $base;
		$length = $paren !== false ? substr($base, $paren) : '';

		$map = [
			'varchar' => 'varbinary',
			'char' => 'binary',
			'tinytext' => 'tinyblob',
			'text' => 'blob',
			'mediumtext' => 'mediumblob',
			'longtext' => 'longblob',
		];

		$binary = $map[$name] ?? null;
		if ($binary === null) {
			throw InvalidState::create()
				->withMessage(
					'binaryTypeFor() called with unmappable column type ' . $columnType
					. '; only char/varchar/text family types are valid.',
				);
		}

		return $binary . ($binary === 'varbinary' || $binary === 'binary' ? $length : '');
	}

	/**
	 * Only the included tables' columns are read from the by-table index, so an excluded table's column
	 * rows are never touched in PHP. Within each included table the charset-less columns (no string data)
	 * are dropped, matching the previous flat-scan filter.
	 *
	 * @param array<string, bool> $includedNames
	 * @return array<string, list<array{
	 *     TABLE_NAME: string, COLUMN_NAME: string, COLUMN_TYPE: string, DATA_TYPE: string,
	 *     CHARACTER_SET_NAME: string|null, COLLATION_NAME: string|null,
	 *     IS_NULLABLE: string, COLUMN_DEFAULT: string|null, EXTRA: string, COLUMN_COMMENT: string,
	 *     GENERATION_EXPRESSION: string|null, CHARACTER_MAXIMUM_LENGTH: int|null, ORDINAL_POSITION: int
	 * }>>
	 */
	private function getColumnsByTable(array $includedNames): array
	{
		$columnsIndex = $this->schema->getColumnsByTable();

		$byTable = [];
		foreach ($includedNames as $name => $included) {
			foreach ($columnsIndex[$name] ?? [] as $column) {
				if ($column['CHARACTER_SET_NAME'] === null) {
					continue;
				}

				$byTable[$name][] = $column;
			}
		}

		return $byTable;
	}

	/**
	 * Only the included tables' index rows are read from the by-table index, leaving excluded tables'
	 * statistics untouched in PHP.
	 *
	 * @param array<string, bool> $includedNames
	 * @return list<array{TABLE_NAME: string, COLUMN_NAME: string}>
	 */
	private function getUniqueIndexColumns(array $includedNames): array
	{
		$statisticsIndex = $this->schema->getStatisticsByTable();

		$rows = [];
		foreach ($includedNames as $name => $included) {
			foreach ($statisticsIndex[$name] ?? [] as $row) {
				if ($row['NON_UNIQUE'] !== 0) {
					continue;
				}

				$rows[] = [
					'TABLE_NAME' => $row['TABLE_NAME'],
					'COLUMN_NAME' => $row['COLUMN_NAME'],
				];
			}
		}

		return $rows;
	}

	/**
	 * @return list<array{TABLE_NAME: string, ENGINE: string|null, ROW_FORMAT: string|null, TABLE_COLLATION: string|null}>
	 */
	private function getTables(): array
	{
		return $this->schema->getTables();
	}

	/**
	 * @return list<array{COLLATION_NAME: string}>
	 */
	private function getAvailableCollationRows(): array
	{
		$rows = [];
		foreach ($this->schema->getCollations() as $collation) {
			if ($collation['CHARACTER_SET_NAME'] !== 'utf8mb4') {
				continue;
			}

			$rows[] = ['COLLATION_NAME' => $collation['COLLATION_NAME']];
		}

		return $rows;
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

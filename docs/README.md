# DB Audit

SQL database analysis for common errors in structure, data and configuration

## Content

- [Quick start](#quick-start)
- [Concepts](#concepts)
- [Auditors](#auditors)
	- [Functional auditors](#functional-auditors)
	- [Stylistic auditors](#stylistic-auditors)
	- [Configuration & shared schema](#configuration--shared-schema)
- [Running auditors](#running-auditors)
	- [How fixes are generated](#how-fixes-are-generated)
	- [Ignoring errors and baselines](#ignoring-errors-and-baselines)
	- [CLI commands](#cli-commands)
- [Outdated collation migration](#outdated-collation-migration)
	- [What it does](#what-it-does)
	- [Usage](#usage)
	- [Default behavior](#default-behavior)
	- [Configuration](#configuration)
		- [Target collation policy](#target-collation-policy)
		- [utf8mb3 conversion](#utf8mb3-conversion)
		- [Legacy charset handling (latin1 etc.)](#legacy-charset-handling-latin1-etc)
		- [Database default](#database-default)
		- [Unique index collisions](#unique-index-collisions)
		- [Row format and index length](#row-format-and-index-length)
		- [Lock mode](#lock-mode)
		- [Excluded tables](#excluded-tables)
	- [Foreign keys, sessions and idempotency](#foreign-keys-sessions-and-idempotency)
	- [Warning about explicit charset/collation references](#warning-about-explicit-charsetcollation-references)
- [Unique-index collation collision auditor](#unique-index-collation-collision-auditor)
	- [What it does](#what-it-does-1)
	- [Usage](#usage-1)
- [Latin1 encoding auditor](#latin1-encoding-auditor)
	- [What it does](#what-it-does-2)
	- [Usage](#usage-2)
	- [Verdicts](#verdicts)
- [Sharing the schema across auditors](#sharing-the-schema-across-auditors)
- [Setup](#setup)

## Quick start

Install with [Composer](https://getcomposer.org):

```sh
composer require orisai/db-audit
```

Every auditor needs a [`DbalAdapter`](../src/Dbal/DbalAdapter.php). Adapters for
[dibi](https://github.com/dg/dibi) and [Nextras Dbal](https://github.com/nextras/dbal) ship with the library:

```php
use Dibi\Connection;
use Orisai\DbAudit\Dbal\DibiAdapter;

$dbal = new DibiAdapter(new Connection([
	'driver' => 'mysqli',
	'host' => '127.0.0.1',
	'port' => 3306,
	'username' => 'root',
	'password' => 'root',
	'database' => 'my_database',
]));
```

Generate a migration that moves the current database to `utf8mb4`:

```php
use Orisai\DbAudit\Auditor\OutdatedCollationMysqlAuditor;
use Orisai\DbAudit\Runner\Runner;

$runner = new Runner($dbal, [new OutdatedCollationMysqlAuditor($dbal)]);

echo $runner->generate()->getSql(); // the migration SQL
```

## Concepts

Every auditor implements [`Analyser`](../src/Analyser.php):

- `Analyser::getCategory()` returns the auditor's [`AnalyserCategory`](../src/AnalyserCategory.php) (`structure` or
  `data`); `Analyser::getSupportedDatabases()` returns the engines and minimum versions it runs on.
- `Analyser::analyse()` returns an [`AnalysisResult`](../src/Report/AnalysisResult.php) with `getViolations()`
  (a `list` of [`Violation`](../src/Report/Violation.php)) and `getAdvisories()` (a `list` of
  [`Advisory`](../src/Report/Advisory.php)). A **violation** is a problem found in the schema/data; an **advisory** is
  guidance for the operator (e.g. "scan your code for explicit charset references") that is not itself a defect. A
  violation carries a stable `getKey()` **identifier** (e.g. `outdated_collation.utf8mb3`), a `getMessage()`, an
  `isFixable()` flag, an optional `getHint()`, and a `getSource()`
  ([`TableViolationSource`](../src/Report/TableViolationSource.php) or
  [`ColumnViolationSource`](../src/Report/ColumnViolationSource.php)) locating it.

Auditors that can *fix* what they report tag the relevant `Violation`s with declarative
[`Change\ChangeRequest`](../src/Change/ChangeRequest.php)s. The [`Runner`](../src/Runner/Runner.php) collects those
across all auditors and resolves them into one migration: `(new Runner($dbal, [$auditor]))->generate()` returns a
[`GenerationReport`](../src/Runner/GenerationReport.php) with `getSql()` (the migration SQL), `getGeneratedCount()`,
`getAdvisories()` (operator guidance) and `getUnfixable()` (problems it could not auto-fix). **`generate()` never
executes anything** — it only builds a string, so it is safe to run against any database; you review the SQL and apply
it yourself.

The current database is the one selected on the connection (`DATABASE()`); point the connection at the database you
want to audit.

> **Single-database scope.** Every auditor operates on the one current database. **Cross-database (cross-schema)
> relationships are not supported** and will produce false reports — for example, a foreign key that references a
> table in another database is treated as if its target does not exist (a false "referenced table does not exist"
> finding) and is skipped by the type-mismatch and orphan checks. Audit each database with its own connection; do not
> rely on these auditors for foreign keys that cross database boundaries. Cross-database support may be added later.

## Auditors

All auditors live in `Orisai\DbAudit\Auditor`, are constructed with a
[`Schema\SchemaProvider`](../src/Schema/SchemaProvider.php), and work on **MySQL 8.0+ and MariaDB 10.11+**. Each
declares its category (`getCategory()`) and the databases it supports (`getSupportedDatabases()`), and reports
`Violation`s through `analyse()`. Every distinct finding carries a stable
**identifier** (`Violation::getKey()`, e.g. `outdated_collation.utf8mb3`) used for ignoring and baselines — see
[Running auditors](#running-auditors). Two of them have dedicated sections below:
[outdated collation](#outdated-collation-migration) (produces migration SQL via the `Runner`) and
[latin1 encoding](#latin1-encoding-auditor).

Auditors are grouped by **importance** first. **Functional** auditors flag correctness, data-integrity and operational
problems — broken referential integrity, data loss, invalid values, imminent outages — and should be triaged and fixed
**before** the **stylistic** ones, which flag naming, convention and schema-hygiene issues that rarely cause incorrect
behavior on their own. A secondary **Type** column marks whether an auditor only reads schema metadata
(*Structure* — `INFORMATION_SCHEMA`, no row scanning) or scans table rows (*Data* — column-data auditors share one
profiling scan per table; `EmptyTable` uses a single-row probe per table; `AutoIncrementNearLimit` reads only
`INFORMATION_SCHEMA` metadata, no rows).

### Functional auditors

Correctness, data integrity and operational risk — fix these first.

| Auditor | Key | Type | Detects |
| --- | --- | --- | --- |
| `ForeignKeyViolationMysqlAuditor` | `foreign_key_violation` | Data | Orphan rows whose foreign-key value has no matching referenced row (composite-key aware, MATCH SIMPLE). |
| `ForeignKeyReferencedColumnExistenceMysqlAuditor` | `foreign_key_referenced_column_existence` | Structure | Foreign keys whose referenced table does not exist. |
| `ForeignKeyColumnTypeMismatchMysqlAuditor` | `foreign_key_column_type_mismatch` | Structure | Foreign-key columns whose data type or character set differs from the referenced column. |
| `MissingPrimaryKeyMysqlAuditor` | `missing_primary_key` | Structure | Base tables with no `PRIMARY KEY` (a UNIQUE key does not count) — no reliable row identity, hurts row-based replication. |
| `NonTransactionalEngineMysqlAuditor` | `non_transactional_engine` | Structure | Base tables not using InnoDB (e.g. MyISAM, MEMORY) — no transactions/foreign keys/crash safety. |
| `OutdatedCollationMysqlAuditor` | `outdated_collation` | Structure | Outdated character sets / collations (utf8mb3 and legacy charsets) that cannot store the full Unicode range. Tags change requests — the `Runner` produces utf8mb4 migration SQL. See [Outdated collation migration](#outdated-collation-migration). |
| `Latin1EncodingMysqlAuditor` | `latin1_encoding` | Data | Single-byte (latin1-family) columns classified as genuine vs double-encoded UTF-8. See [Latin1 encoding auditor](#latin1-encoding-auditor). |
| `UniqueIndexCollationCollisionMysqlAuditor` | `unique_index_collation_collision` | Data | Unique indexes whose data would collide under the target collation — use it to decide whether `setForceUniqueIndexConversion(true)` is safe. See [Unique-index collation collision auditor](#unique-index-collation-collision-auditor). |
| `InvalidDateMysqlAuditor` | `invalid_date` | Data | Date/time columns containing zero / invalid dates. |
| `InvalidDefaultDateMysqlAuditor` | `invalid_default_date` | Structure | Columns whose `DEFAULT` is a zero / invalid date (`0000-00-00`, zero month/day). |
| `MixedEmptyValuesMysqlAuditor` | `mixed_empty_values` | Data | String columns that mix `NULL` and `''` (empty string) — an ambiguous "missing value" representation that breaks queries. |
| `AutoIncrementNearLimitMysqlAuditor` | `auto_increment_near_limit` | Data | `AUTO_INCREMENT` columns near their type's maximum value — inserts will start failing (threshold configurable, default 90%). |

### Stylistic auditors

Naming, convention and schema hygiene — lower priority; resolve after the functional findings.

| Auditor | Key | Type | Detects |
| --- | --- | --- | --- |
| `ForeignKeyColumnNameMismatchMysqlAuditor` | `foreign_key` | Structure | Columns matching a foreign-key naming pattern that have no foreign key, and foreign keys on columns that do not match the pattern. Pattern configurable (default `/_id$/`). |
| `NullableWithNoNullsMysqlAuditor` | `nullable_with_no_nulls` | Data | Nullable columns in non-empty tables that contain no `NULL` — candidates for `NOT NULL`. |
| `BoolLikeColumnMysqlAuditor` | `bool_like_column` | Data | Integer columns holding only `0`/`1` that are not `tinyint` or lack a `CHECK (col IN (0,1))` — likely booleans. |
| `RedundantIndexMysqlAuditor` | `redundant_index` | Structure | Non-unique indexes whose ordered columns are a prefix of (or duplicate) another index, adding write and storage overhead (PRIMARY and UNIQUE indexes are never flagged). |
| `EmptyTableMysqlAuditor` | `empty_table` | Data | Tables with no rows (exact `COUNT`, not the InnoDB row estimate) — often dead schema. |
| `EmptyColumnMysqlAuditor` | `empty_column` | Data | Columns whose every value is `NULL` (and, for string columns, `NULL` or `''`) — often unused. |

### Configuration & shared schema

Most auditors need only the `DbalAdapter`. Three accept configuration:

- `OutdatedCollationMysqlAuditor` — an [`OutdatedCollationConfig`](../src/Collation/OutdatedCollationConfig.php) (see
  [Configuration](#configuration)).
- `ForeignKeyColumnNameMismatchMysqlAuditor` — a `ForeignKeyColumnNameMismatchConfig` with `setPattern(string)` (the
  PCRE pattern identifying a foreign-key-looking column name; default `/_id$/`).
- `AutoIncrementNearLimitMysqlAuditor` — `setPercentileThreshold(int)` (default `90`).

Nine auditors additionally accept an optional shared [`Schema\SchemaProvider`](../src/Schema/SchemaProvider.php) as
their last constructor argument, so several can share one cached, scoped schema fetch (see
[Sharing the schema across auditors](#sharing-the-schema-across-auditors)): `MissingPrimaryKey`,
`NonTransactionalEngine`, `RedundantIndex`, `OutdatedCollation`, `ForeignKeyColumnTypeMismatch`,
`ForeignKeyReferencedColumnExistence`, `ForeignKeyColumnNameMismatch`, `ForeignKeyViolation`, and `Latin1Encoding`.
The remaining auditors — `EmptyTable`, `EmptyColumn`, `NullableWithNoNulls`, `MixedEmptyValues`, `BoolLikeColumn`,
`InvalidDate`, `InvalidDefaultDate`, and `AutoIncrementNearLimit` — read what they need directly and take only the
`DbalAdapter`.

## Running auditors

Register the auditors you want explicitly and run them through [`Runner\Runner`](../src/Runner/Runner.php). The runner
reads the live server version once, **skips** any auditor whose `getSupportedDatabases()` does not cover it (recording a
[`Report\Warning`](../src/Report/Warning.php)), runs the rest, applies the relevant ignores, and returns a report.

```php
use Orisai\DbAudit\AnalyserCategory;
use Orisai\DbAudit\Auditor\EmptyTableMysqlAuditor;
use Orisai\DbAudit\Auditor\MissingPrimaryKeyMysqlAuditor;
use Orisai\DbAudit\Runner\Runner;

$runner = new Runner($dbal, [
	new MissingPrimaryKeyMysqlAuditor($dbal), // structure
	new EmptyTableMysqlAuditor($dbal),        // data
]);

$report = $runner->analyse();                              // both categories
$structure = $runner->analyse(AnalyserCategory::structure()); // one category
```

Each auditor declares one [`AnalyserCategory`](../src/AnalyserCategory.php) — `structure` (checkable against the schema
in CI) or `data` (must run against real data). `analyse()` returns an
[`AnalysisReport`](../src/Runner/AnalysisReport.php) (`getErrors()`, `getIgnoredCount()`, `getWarnings()`,
`getUnmatchedIgnores()`, and `hasErrors()` — true when there are errors **or** unmatched ignores). `generate()` returns
a [`GenerationReport`](../src/Runner/GenerationReport.php) with the combined `getSql()`, `getGeneratedCount()` (the exact
number of fixes produced), `getUnfixable()`, `getAdvisories()` and `getWarnings()`.

### How fixes are generated

Auditors do not write SQL. When a finding is fixable, the auditor *tags* the violation with a declarative
[`Change\ChangeRequest`](../src/Change/ChangeRequest.php) (`Violation::isFixable()` / `getChange()`) describing the
desired end state. Five auditors currently tag changes:

| Auditor | Change | Result |
| --- | --- | --- |
| `NonTransactionalEngineMysqlAuditor` | [`TableEngineChange`](../src/Change/TableEngineChange.php) | `ENGINE=InnoDB` |
| `RedundantIndexMysqlAuditor` | [`DropIndexChange`](../src/Change/DropIndexChange.php) | `DROP INDEX …` |
| `OutdatedCollationMysqlAuditor` | [`ColumnTargetChange`](../src/Change/ColumnTargetChange.php) | `MODIFY … CHARACTER SET utf8mb4 COLLATE …` |
| `NullableWithNoNullsMysqlAuditor` | [`ColumnTargetChange`](../src/Change/ColumnTargetChange.php) | `MODIFY … NOT NULL` (only for simple columns — no explicit default; others stay non-fixable) |
| `ForeignKeyColumnTypeMismatchMysqlAuditor` | [`ColumnTargetChange`](../src/Change/ColumnTargetChange.php) | Widens child `char`/`varchar` length to the parent's; aligns child charset to the parent's when non-lossy (child MAXLEN < parent MAXLEN, e.g. `latin1` → `utf8mb4`). Both fixes may appear on one `MODIFY`. Base-type, sign, numeric-size, and lossy-charset mismatches stay **report-only**. |

A single [`Change\MigrationPlanner`](../src/Change/MigrationPlanner.php), one layer above the auditors, then:

- drops the change requests of **ignored** violations (so no SQL is produced for an ignored error),
- **merges** every change for a table — across auditors — into one `ALTER TABLE` (via a
  [`Change\MigrationStrategy`](../src/Change/MigrationStrategy.php) — only
  [`BasicMigrationStrategy`](../src/Change/BasicMigrationStrategy.php) ships now; the seam allows zero-downtime or
  copy-based strategies later), so a table flagged by several auditors is rebuilt once, not once per change,
- **refuses conflicts:** two requests that change the same attribute of one object differently are dropped and reported
  as unfixable `change.conflict` violations (the rest still generate), the way a linter refuses opposing fixes.

#### Delta change model

`ColumnTargetChange` is a **sparse delta**: the auditor sets only the fields it changes and leaves everything else
unset. `OutdatedCollationMysqlAuditor` calls `setCharsetCollation(...)` on `ColumnTargetChange::forColumn(...)`;
`NullableWithNoNullsMysqlAuditor` calls `setNullable(false)`. When both auditors flag the same column, the planner
**merges the two deltas onto the current column definition** from the live schema — producing one `MODIFY` with both
changes and the unchanged fields (DEFAULT, COMMENT, …) carried over from the current definition unmodified. A
**conflict** is raised only when two auditors set the **same field to different values** (e.g. two different target
charsets); cross-field infeasibility (e.g. the merged charset change causes a key-length overflow) surfaces through
the usual `change.index_too_long` refusal.

### Ignoring errors and baselines

Errors are ignored PHPStan-style with [`Ignore\IgnoredError`](../src/Ignore/IgnoredError.php). An entry combines any of
`rawMessage` (exact), `message` (PCRE), `key` (the identifier), `table`, `column` and `count` — at least one is
required, and `rawMessage`/`message` are mutually exclusive. An error is ignored when **all** the entry's set criteria
match; with a `count`, only the first *count* matching errors are ignored and the rest are reported. An entry that
matches **fewer** errors than its `count` (including zero) is itself reported, so stale ignores surface.

Ignores are kept **separately for structure and data**, because structure is typically checked in CI and data against
the production database:

```php
use Orisai\DbAudit\Ignore\Baseline;
use Orisai\DbAudit\Ignore\IgnoredError;
use Orisai\DbAudit\Ignore\IgnoreList;

$runner = new Runner(
	$dbal,
	$analysers,
	Baseline::load(__DIR__ . '/db-audit-baseline-structure.php'),     // structure ignores
	new IgnoreList([new IgnoredError(null, null, 'legacy_table')]),    // ad-hoc data ignores (by table)
);
```

A [`Ignore\Baseline`](../src/Ignore/Baseline.php) is a plain PHP file returning a list of entries (written with
`rawMessage` + `count`, never regex). As in PHPStan, the baseline is a **separate file you load explicitly** into the
wiring above — it is never auto-discovered.

### CLI commands

Two commands (require `symfony/console`) wrap a configured `Runner` and, for the baseline, two configured file paths
(one for structure, one for data — never mixed; `null` disables baselines for that category):

| Command | Purpose |
| --- | --- |
| `db-audit:analyse` | Run the analysers and print each error (message, `identifier`, whether it is `fixable`, optional hint), a per-identifier summary table, a status box and a time/memory footer. `--category=structure\|data\|all` is **required**. `-b`/`--generate-baseline` writes all current errors to the configured baseline(s) for the selected categories and succeeds; without it, the configured baseline(s) are subtracted from the reported errors first and the command fails iff any non-baselined error remains. `--generate-fix=PATH` composes the fixes for a **single** category (not `all`, and never combined with `-b`) and writes the SQL to `PATH`; exits non-zero when anything is unfixable. |
| `db-audit:baseline:remove` | Remove entries from the configured baseline for one category (`--category=structure\|data`, required) matching `--key`, `--raw-message` (exact), `--message` (regex), `--table` and/or `--column` (every provided filter must match). |

```php
use Orisai\DbAudit\Cmd\AnalyseCommand;
use Orisai\DbAudit\Cmd\BaselineRemoveCommand;
use Symfony\Component\Console\Application;

$application = new Application();
$application->add(new AnalyseCommand($runner, 'db-audit-baseline-structure.php', 'db-audit-baseline-data.php'));
$application->add(new BaselineRemoveCommand('db-audit-baseline-structure.php', 'db-audit-baseline-data.php'));
$application->run();
```

```sh
# Structure findings in CI, against the committed schema
php bin/console db-audit:analyse --category=structure

# Accept the current structure findings as a baseline
php bin/console db-audit:analyse --category=structure --generate-baseline

# Re-run: the baseline is subtracted; fails only if new errors remain
php bin/console db-audit:analyse --category=structure

# Produce the migration SQL for a single category (exits non-zero if anything is unfixable)
php bin/console db-audit:analyse --category=structure --generate-fix=migration.sql
```

## Outdated collation migration

[`OutdatedCollationMysqlAuditor`](../src/Auditor/OutdatedCollationMysqlAuditor.php) is both an `Analyser` and a
`Generator`. It works on MySQL 8.0+ and MariaDB 10.x+.

### What it does

- `analyse()` reports (as violations) every database default, table default and column whose character set / collation
  is outdated, plus anything it cannot safely fix; guidance is returned separately as advisories.
- `generate()` returns a single SQL script that converts them to `utf8mb4`, preserving foreign keys and the
  surrounding session state.

### Usage

```php
use Orisai\DbAudit\Auditor\OutdatedCollationMysqlAuditor;
use Orisai\DbAudit\Collation\OutdatedCollationConfig;

$config = new OutdatedCollationConfig();
// ... adjust $config (see Configuration) ...

$auditor = new OutdatedCollationMysqlAuditor($dbal, $config);

$result = $auditor->analyse();
foreach ($result->getViolations() as $violation) {
	echo $violation->getMessage(), "\n";
}
foreach ($result->getAdvisories() as $advisory) {
	echo 'ADVISORY: ', $advisory->getMessage(), "\n";
}

file_put_contents('migration.sql', (new Runner($dbal, [$auditor]))->generate()->getSql());
```

### Default behavior

With a default `OutdatedCollationConfig` the auditor is deliberately conservative — it never changes sort/search
results and never silently risks data:

- **`utf8mb3` → `utf8mb4`**, mapping each collation to its identical `utf8mb4` namesake (`utf8mb3_czech_ci` →
  `utf8mb4_czech_ci`). This is a pure character-set widening: existing data bytes are unchanged, ordering is identical,
  no truncation.
- **Multibyte legacy charsets** (`gbk`, `big5`, `sjis`, …) are converted to `utf8mb4` as well — genuine multibyte data
  transcodes losslessly.
- **Single-byte legacy charsets** (`latin1` and family) are **reported, not converted** — they are ambiguous (see
  [Legacy charset handling](#legacy-charset-handling-latin1-etc)).
- **Already-`utf8mb4`** columns are left untouched (and only reported).
- The **database default** charset is changed too, but only if the executing account has the `ALTER` privilege.
- **Foreign keys** are preserved; **unique indexes** are checked for collation collisions; **row format** is upgraded
  to `DYNAMIC` when the wider charset would otherwise overflow an index.
- The result is **idempotent**: applying the generated SQL and re-running `analyse()` yields no violations.

`generate()->getSql()` is `''` when there is nothing to migrate.

### Configuration

`OutdatedCollationConfig` is a mutable value object with fluent setters. Pass it to the auditor's constructor.

#### Target collation policy

`setTargetPolicy(CollationTargetPolicy)` — how a collation is chosen for the target charset.

- `CollationTargetPolicy::preserveOrder()` *(default)* — map to the literal `utf8mb4` namesake of the source
  collation. Ordering and equality are unchanged. (Charsets with no `utf8mb4` namesake, like `latin1`, fall back to a
  profile-appropriate collation — an unavoidable ordering change for those.)
- `CollationTargetPolicy::modernize()` — pick the most modern server-native collation for each column's
  case/accent profile (e.g. `utf8mb4_0900_as_ci` on MySQL 8, `utf8mb4_uca1400_as_ci` on MariaDB), chosen from the
  collations the server actually has, and also re-collate already-`utf8mb4` columns. **Changes sort/search order**, so
  it is opt-in.

In both policies the case- and accent-sensitivity *profile* of a column is preserved: case-insensitive stays
case-insensitive, accent-sensitive stays accent-sensitive, binary stays binary.

#### utf8mb3 conversion

`setConvertUtf8mb3(bool)` *(default `true`)* — convert `utf8mb3`/`utf8` columns to `utf8mb4`. Set to `false` to leave
them as they are.

#### Legacy charset handling (latin1 etc.)

`setLegacyCharsetConversion(LegacyCharsetConversion)` — governs **single-byte** legacy charsets (`latin1`, `latin2`,
`cp1250`, …). These are ambiguous: a column declared `latin1` may hold genuine latin1 data *or* UTF-8 bytes that were
stored through a mismatched connection ("double-encoded"). The two cases need opposite fixes, and only inspecting the
data tells them apart — see the [Latin1 encoding auditor](#latin1-encoding-auditor).

- `LegacyCharsetConversion::report()` *(default)* — **do not convert**; report each single-byte legacy column (and
  table default) as needing a decision. Safe default — it never risks cementing mojibake or corrupting genuine data.
- `LegacyCharsetConversion::assumeGenuine()` — the data really is latin1: convert straight (`MODIFY … CHARACTER SET
  utf8mb4`), transcoding the characters.
- `LegacyCharsetConversion::assumeDoubleEncoded()` — the data is UTF-8 bytes mislabelled as latin1: convert plain
  `CHAR`/`VARCHAR`/`TEXT` columns via a `VARBINARY` round-trip that reinterprets the bytes. `ENUM`/`SET` and generated
  columns cannot be byte-reinterpreted and are reported instead.

Multibyte legacy charsets (`gbk`, `big5`, …) are **not** affected by this option — they are converted by default
because they are not subject to the double-encoding ambiguity.

#### Database default

`setDatabaseDefault(DatabaseDefaultHandling)` — whether to emit `ALTER DATABASE`.

- `DatabaseDefaultHandling::auto()` *(default)* — read the executing account's grants and emit `ALTER DATABASE` only
  if it has `ALTER` on the database; otherwise skip it and report why (common on shared hosting). Never assumes the
  privilege.
- `DatabaseDefaultHandling::skip()` — never emit `ALTER DATABASE`.

`setExecutionAccount(?string)` *(default `null`)* — the account (`'user@host'`) whose grants gate `ALTER DATABASE`.
Use it when you analyse as a read-only user but the migration will be applied by a different account. `null` means the
connected user.

#### Unique index collisions

`setForceUniqueIndexConversion(bool)` *(default `false`)* — how to treat a collation change on a column under a `UNIQUE`
index (or `PRIMARY KEY`), where a *sensitivity-changing* collation could equate previously-distinct values and break the
rebuild. The decision is schema-only and deterministic (it never reads table data):

- An **order-preserving** conversion (the default `preserveOrder` policy keeps the comparison semantics, e.g.
  `utf8mb3_general_ci` → `utf8mb4_general_ci`, `*_bin` → `utf8mb4_bin`) can never equate two previously-distinct values,
  so it is always converted — independent of `setForceUniqueIndexConversion`.
- A **non-order-preserving** conversion (e.g. under `modernize`, where a case/accent-sensitive collation becomes
  insensitive) *could* create duplicate keys. With `false` it is **refused** (reported as `outdated_collation.unique_index`,
  left unchanged). Set it to `true` to convert anyway — do this only after running
  `UniqueIndexCollationCollisionMysqlAuditor` (see [below](#unique-index-collation-collision-auditor)) to confirm the
  data does not actually collide.

#### Row format and index length

Row-format upgrading is a general migration concern decided by the migration planner, not the collation config: pass
`new MigrationPlanner($autoUpgradeRowFormat = true)` to the `Runner`. `utf8mb4` needs up to 4 bytes per character, so an
index on a long column can exceed InnoDB's 767-byte limit on `COMPACT`/`REDUNDANT` tables. When `true` (the default),
such a table is upgraded to `ROW_FORMAT=DYNAMIC` (3072-byte limit) as part of the migration, computed from the surviving
changes after ignores. An index that would exceed even 3072 bytes cannot be fixed automatically and is reported as
unfixable (no failing SQL is emitted). Pass `new MigrationPlanner(false)` to only report instead of upgrading.

#### Lock mode

Pass a `BasicMigrationStrategy` with an `AlterLock` as the `Runner`'s fifth argument to control the `LOCK` clause on
each `ALTER TABLE`. Character-set conversion rebuilds the table (`ALGORITHM=COPY`), which blocks writes for the
duration. The generated migration SQL is fully escaped (all identifiers and string values go through the dbal adapter).

```php
use Orisai\DbAudit\Change\AlterLock;
use Orisai\DbAudit\Change\BasicMigrationStrategy;
use Orisai\DbAudit\Runner\Runner;

$runner = new Runner($dbal, [$auditor], null, null, new BasicMigrationStrategy($dbal, AlterLock::shared()));
```

- `AlterLock::default()` *(default)* / `AlterLock::none()` — emit no `LOCK` clause; the server picks (most
  portable). `LOCK = NONE` is not supported for charset conversion (it forces `ALGORITHM=COPY`), so it is omitted.
- `AlterLock::shared()` / `AlterLock::exclusive()` — emit `, LOCK = SHARED` / `, LOCK = EXCLUSIVE` on each per-table
  `ALTER`. True zero-downtime requires an external tool such as `pt-online-schema-change`.

#### Excluded tables

`setExcludeTables(TableNameFilter)` — tables to leave untouched. Build a filter from literal names and/or glob
patterns (`*`/`?` wildcards; `_` is literal):

```php
use Orisai\DbAudit\Collation\TableNameFilter;

$config->setExcludeTables(
	(new TableNameFilter())
		->withName('legacy_table')
		->withGlob('_*'), // every table starting with an underscore
);
```

Excluding a table leaves its columns and table default untouched — **except** a foreign-key relationship is only
ignored when **both** of its tables are excluded. If a foreign key crosses the exclusion boundary (one side excluded,
the other not), the relationship is still in scope: the foreign-key column on **both** sides is converted (just that
column on the excluded table — its other columns stay untouched) and the constraint is dropped and recreated, so the
foreign key never ends up mismatched.

### Foreign keys, sessions and idempotency

The generated script:

- saves and restores `@@SESSION.foreign_key_checks` (it disables checks during the run, then restores the *previous*
  value, so chained migrations keep their assumed defaults);
- drops the involved foreign keys, converts the columns, and recreates the foreign keys with their original names,
  columns and `ON DELETE` / `ON UPDATE` actions;
- terminates every statement so it can be piped straight into a client.

A foreign key's columns are **driven by the referenced (parent) column**: each referencing column takes the parent
column's target charset/collation, propagated transitively along foreign-key chains (including cycles), so both sides
always converge to the same collation and the recreated constraint stays type-compatible. A referencing column is also
**never left narrower than the column it references** — if the parent is longer, the child is widened to match (a child
that is already wider keeps its length; widening only ever grows, never truncates). This alignment happens **only as a
side effect of converting that column** (like the row-format upgrade) — a column that is not otherwise being migrated
is left untouched, so an already-`utf8mb4` schema stays a no-op. Other attributes (nullability, default) stay
per-column.

If **either** endpoint of a foreign key cannot be migrated for any reason — a unique-index collision, an index that
would exceed the key-length limit even at `ROW_FORMAT=DYNAMIC` (including after the child is widened), a single-byte
legacy column in `report` mode, etc. — then **neither** side is converted and the foreign key is left in place, with a
violation reported. This keeps the script from ever emitting an `ALTER` that fails or dropping a foreign key it cannot
recreate.

### Warning about explicit charset/collation references

Changing a column's charset/collation can break SQL that **explicitly** references the old one — e.g.
`COLLATE utf8mb3_czech_ci` on a now-`utf8mb4` column (a hard error), `CONVERT(expr USING utf8mb3)`, a charset
introducer `_utf8mb3'…'`, or `CHARACTER SET utf8mb3` / `CAST(… CHARACTER SET utf8mb3)`.

When a migration is produced, the **advisories** (`analyse()->getAdvisories()` / `generate()->getAdvisories()`) include
one that lists the exact charsets/collations being migrated (so you know what to grep for) and instructs you to
**search your application code** for these references — the tool cannot inspect application code. In addition, it scans
the **schema-stored** SQL it *can* see — views, stored routines, triggers, events and generated-column expressions —
and names any object that references a migrated charset/collation, so you can review those directly. These advisories
are available from `generate()->getAdvisories()` (and `analyse()->getAdvisories()`); render or log them from your own
wiring. The migration is produced and configured as shown in [Configuration](#configuration) and run through the generic
[`db-audit:analyse --generate-fix`](#cli-commands) command.

## Unique-index collation collision auditor

[`UniqueIndexCollationCollisionMysqlAuditor`](../src/Auditor/UniqueIndexCollationCollisionMysqlAuditor.php) answers
the question `OutdatedCollationMysqlAuditor` cannot: for a unique index whose collation is changing in a
**non-order-preserving** way (e.g. under `modernize`), **does the current data actually collide under the target
collation?** Run it to decide whether `setForceUniqueIndexConversion(true)` is safe.

### What it does

`analyse()` is a `data()` auditor — run it against production (or a representative copy) data, not the committed
schema. For every unique index that would convert to a sensitivity-changing target collation, it executes a live probe:
it groups the rows by `CONVERT(col USING <target charset>) COLLATE <target collation>` and checks whether any group
has more than one member. A violation (`unique_index_collation_collision`) is reported only for indexes where the probe
finds a collision; indexes with no colliding rows are clean and `setForceUniqueIndexConversion(true)` is safe for
them.

> **Transcoding assumption.** The probe simulates a straight `CONVERT(col USING utf8mb4)` transcoding — the
> assume-genuine path. For a single-byte legacy (`latin1`-family) column you plan to migrate via the
> assume-double-encoded VARBINARY two-step, the post-migration bytes differ (the two-step reinterprets the stored
> bytes as UTF-8 rather than transcoding them), so the collision result for such a column is indicative only. Resolve
> the legacy conversion mode first (see [Legacy charset handling](#legacy-charset-handling-latin1-etc) and the
> [Latin1 encoding auditor](#latin1-encoding-auditor)), then re-run the probe if needed.

### Usage

```php
use Orisai\DbAudit\Auditor\UniqueIndexCollationCollisionMysqlAuditor;
use Orisai\DbAudit\Collation\OutdatedCollationConfig;
use Orisai\DbAudit\Collation\CollationTargetPolicy;

$config = new OutdatedCollationConfig();
$config->setTargetPolicy(CollationTargetPolicy::modernize());

$auditor = new UniqueIndexCollationCollisionMysqlAuditor($dbal, $config);

foreach ($auditor->analyse()->getViolations() as $violation) {
	echo $violation->getMessage(), "\n";
	echo 'Hint: ', $violation->getHint(), "\n";
}
```

Pass the same `OutdatedCollationConfig` you use for `OutdatedCollationMysqlAuditor` — the auditor reads the target
policy and excluded tables from it. A clean run (no violations) means no index would gain duplicate keys, so it is
safe to set `setForceUniqueIndexConversion(true)` and proceed with the migration. A violation means you must
deduplicate the colliding rows first.

## Latin1 encoding auditor

[`Latin1EncodingMysqlAuditor`](../src/Auditor/Latin1EncodingMysqlAuditor.php) answers the question the collation
migration cannot: for a single-byte (`latin1`-family) text column, **is the stored data genuine single-byte text or
double-encoded UTF-8?** Run it to decide which `LegacyCharsetConversion` mode a column needs.

### What it does

`analyse()` scans the *data* of every free-text column (`CHAR`/`VARCHAR`/`TEXT` family) whose charset is single-byte
(`MAXLEN = 1`) in the current database and classifies the bytes of each value:

- pure ASCII — encoding-agnostic, ignored;
- has high bytes that are **not** valid UTF-8 — **genuine** single-byte data;
- has high bytes that **are** valid UTF-8 — **likely double-encoded** UTF-8.

It reports a violation only for columns that contain non-ASCII data. (It is a read-only data scan; on large tables it
reads every row of the scanned columns.)

### Usage

```php
use Orisai\DbAudit\Auditor\Latin1EncodingMysqlAuditor;

$auditor = new Latin1EncodingMysqlAuditor($dbal);

foreach ($auditor->analyse()->getViolations() as $violation) {
	echo $violation->getMessage(), "\n";
}
```

### Verdicts

| Column contents | Verdict | Suggested migration mode |
| --- | --- | --- |
| only genuine (non-UTF-8) high bytes | genuine single-byte | `LegacyCharsetConversion::assumeGenuine()` |
| only valid-UTF-8 high bytes | likely double-encoded | `LegacyCharsetConversion::assumeDoubleEncoded()` (spot-check first) |
| both kinds in the same column | **mixed** — no single mode is safe | manual repair |

By default the [collation migration](#legacy-charset-handling-latin1-etc) only *reports* single-byte legacy columns;
use this auditor's verdict to choose `assumeGenuine()` or `assumeDoubleEncoded()` per database (or to discover a mixed
column that needs hand-repair before any migration).

## Sharing the schema across auditors

Both auditors read `INFORMATION_SCHEMA` through a [`Schema\SchemaProvider`](../src/Schema/SchemaProvider.php). The
heavy reads (columns, index statistics) are **scoped to what the auditors actually need** rather than loading the whole
schema: each auditor declares a [`Schema\SchemaRequest`](../src/Schema/SchemaRequest.php) (which tables — honouring
excludes and foreign-key-related tables — and which columns), and a [`Schema\SchemaCoordinator`](../src/Schema/SchemaCoordinator.php)
unions those requests and fetches **once**. This keeps memory proportional to the in-scope tables (not the whole
database) while still sharing one snapshot across auditors.

If you run a single auditor and let it self-provision its provider (pass no `$schema`), it primes itself per run — no
extra wiring needed. To run several auditors against one shared, scoped fetch, build a provider, hand it to each
auditor, and prime it through the coordinator before analysing:

```php
use Orisai\DbAudit\Auditor\Latin1EncodingMysqlAuditor;
use Orisai\DbAudit\Auditor\OutdatedCollationMysqlAuditor;
use Orisai\DbAudit\Schema\SchemaCoordinator;
use Orisai\DbAudit\Schema\SchemaProvider;

$schema = new SchemaProvider($dbal);
$collation = new OutdatedCollationMysqlAuditor($dbal, $config, $schema);
$encoding = new Latin1EncodingMysqlAuditor($dbal, $schema);

// Union the auditors' requirements and fetch the schema once, scoped to their needs.
(new SchemaCoordinator($schema))->prime([
	$collation->getSchemaRequest(),
	$encoding->getSchemaRequest(),
]);

$collation->generate();
$encoding->analyse();
```

When you pass a shared provider, prime it via the coordinator first; an unprimed shared provider falls back to loading
all columns. A shared provider holds its snapshot until you re-prime it — re-prime (or build a fresh one) after you
apply schema changes. A self-provisioned provider re-primes automatically each run, so the apply-then-re-`analyse()`
round trip sees the new schema.

## Setup

Install with [Composer](https://getcomposer.org):

```sh
composer require orisai/db-audit
```

The console command additionally requires `symfony/console`:

```sh
composer require symfony/console
```

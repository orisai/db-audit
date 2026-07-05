# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](http://semver.org/spec/v2.0.0.html).

## [Unreleased](https://github.com/orisai/db-audit/compare/...v1.x)

### Added

### Changed

- Data auditors (`EmptyColumn`, `EmptyTable`, `NullableWithNoNulls`, `BoolLikeColumn`, `MixedEmptyValues`, `InvalidDate`, `AutoIncrementNearLimit`)
  - honor the `SchemaProvider` table exclude
  - share a single profiling scan per table instead of per-column stored-procedure scans — orders of magnitude faster on large or many-table databases
  - no longer require `CREATE ROUTINE` / `CREATE TEMPORARY TABLES` privileges
  - upgrade note: a run of a previous version that aborted mid-scan may have left `OrisaiDbAudit_*` stored procedures behind; drop them manually (`DROP PROCEDURE IF EXISTS OrisaiDbAudit_...`) — new versions no longer create any
- `NextrasAdapter` no longer connects to the database in its constructor — the connection is established on first use

<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Collation\TableNameFilter;
use Orisai\DbAudit\Report\AnalysisResult;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;
use Orisai\DbAudit\Schema\ColumnCharsetClass;
use Orisai\DbAudit\Schema\SchemaRequest;
use function in_array;
use function strcmp;
use function strtolower;
use function usort;

final class Latin1EncodingMysqlAuditor extends Latin1EncodingAuditor
{

	/**
	 * Only single-byte (latin1-family) text columns can hold the ambiguous bytes this auditor classifies,
	 * across every table; it never reads index statistics.
	 */
	public function getSchemaRequest(): SchemaRequest
	{
		return new SchemaRequest(
			ColumnCharsetClass::singleByte(),
			new TableNameFilter(),
			false,
			false,
			false,
		);
	}

	public function analyse(): AnalysisResult
	{
		$this->primeOwnedSchema();
		$columns = $this->getColumns();

		$this->dbal->exec('SET @ORISAI_DBAUDIT_SQL_MODE = @@SESSION.sql_mode');
		// Clearing sql_mode is required for portability: under the default strict mode MySQL 8.0 turns the
		// invalid-UTF-8 CONVERT() into an error that silently zeroes the invalid_utf8 count, while MariaDB
		// keeps counting; with sql_mode empty both engines classify byte-for-byte identically.
		$this->dbal->exec("SET SESSION sql_mode = ''");

		try {
			$violations = [];
			foreach ($columns as $column) {
				$violation = $this->classifyColumn($column);
				if ($violation !== null) {
					$violations[] = $violation;
				}
			}
		} finally {
			$this->dbal->exec('SET SESSION sql_mode = @ORISAI_DBAUDIT_SQL_MODE');
		}

		return new AnalysisResult($violations);
	}

	/**
	 * @param array{TABLE_SCHEMA: string, TABLE_NAME: string, COLUMN_NAME: string, COLUMN_TYPE: string} $column
	 */
	private function classifyColumn(array $column): ?Violation
	{
		$counts = $this->countEncodings($column['TABLE_NAME'], $column['COLUMN_NAME']);
		if ($counts['non_ascii'] === 0) {
			return null;
		}

		$source = new ColumnViolationSource(
			$column['TABLE_SCHEMA'],
			null,
			$column['TABLE_NAME'],
			$column['COLUMN_NAME'],
		);
		$source->setColumnType($column['COLUMN_TYPE']);

		return new Violation(
			'latin1_encoding',
			'Column ' . $source->toString() . ' ' . $this->verdict($counts),
			$source,
		);
	}

	/**
	 * @param array{non_ascii: int, valid_utf8: int, invalid_utf8: int} $counts
	 */
	private function verdict(array $counts): string
	{
		$valid = $counts['valid_utf8'];
		$invalid = $counts['invalid_utf8'];

		if ($valid > 0 && $invalid > 0) {
			return 'holds MIXED single-byte data: ' . $invalid . ' row(s) are genuine single-byte and '
				. $valid . ' row(s) are double-encoded UTF-8 — no single conversion mode is safe, manual repair required.';
		}

		if ($valid > 0) {
			return 'holds likely double-encoded UTF-8 (' . $valid . ' non-ASCII row(s) form valid UTF-8) —'
				. ' use assume-double-encoded after spot-checking samples.';
		}

		return 'holds genuine single-byte data (' . $invalid . ' non-ASCII row(s) are not valid UTF-8) —'
			. ' safe to convert as genuine (assume-genuine).';
	}

	/**
	 * @return array{non_ascii: int, valid_utf8: int, invalid_utf8: int}
	 */
	private function countEncodings(string $table, string $column): array
	{
		$tableId = $this->dbal->escapeIdentifier($table);
		$columnId = $this->dbal->escapeIdentifier($column);

		$binary = 'CAST(' . $columnId . ' AS BINARY)';
		// A byte >= 0x80 cannot survive a round-trip through the ASCII charset (it is replaced), so a value
		// whose raw bytes differ from its ASCII re-encoding contains at least one high byte. This avoids
		// REGEXP over BINARY, which MySQL 8.0 rejects ("charset 'binary' cannot be used ... in regexp_like").
		$hasHigh = $columnId . ' IS NOT NULL AND ' . $binary
			. ' <> CAST(CONVERT(' . $columnId . ' USING ascii) AS BINARY)';
		// The raw bytes are valid UTF-8 iff reading them as utf8mb4 and writing them back to BINARY is the
		// identity; a genuine single-byte value (e.g. lone 0xE9) is not valid UTF-8 and fails the round-trip.
		$validUtf8 = 'CONVERT(CONVERT(' . $binary . ' USING utf8mb4) USING binary) = ' . $binary;

		$sql = 'SELECT'
			. ' CAST(SUM(CASE WHEN ' . $hasHigh . ' THEN 1 ELSE 0 END) AS UNSIGNED) AS non_ascii,'
			. ' CAST(SUM(CASE WHEN (' . $hasHigh . ') AND (' . $validUtf8 . ') THEN 1 ELSE 0 END) AS UNSIGNED) AS valid_utf8,'
			. ' CAST(SUM(CASE WHEN (' . $hasHigh . ') AND NOT (' . $validUtf8 . ') THEN 1 ELSE 0 END) AS UNSIGNED) AS invalid_utf8'
			. ' FROM ' . $tableId;

		// phpcs:ignore SlevomatCodingStandard.PHP.RequireExplicitAssertion.RequiredExplicitAssertion
		/** @var literal-string $literalSql */
		$literalSql = $sql;

		$row = $this->dbal->query($literalSql)[0];

		return [
			'non_ascii' => (int) $row['non_ascii'],
			'valid_utf8' => (int) $row['valid_utf8'],
			'invalid_utf8' => (int) $row['invalid_utf8'],
		];
	}

	/**
	 * @return list<array{TABLE_SCHEMA: string, TABLE_NAME: string, COLUMN_NAME: string, COLUMN_TYPE: string}>
	 */
	private function getColumns(): array
	{
		$singleByteCharsets = [];
		foreach ($this->schema->getCharacterSets() as $charset) {
			if ($charset['MAXLEN'] === 1) {
				$singleByteCharsets[$charset['CHARACTER_SET_NAME']] = true;
			}
		}

		$schemaName = $this->schema->getDatabaseDefault()['name'];
		$textTypes = ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext'];

		$columns = [];
		foreach ($this->schema->getColumns() as $column) {
			$charset = $column['CHARACTER_SET_NAME'];
			if (
				$charset === null
				|| !isset($singleByteCharsets[$charset])
				|| !in_array(strtolower($column['DATA_TYPE']), $textTypes, true)
			) {
				continue;
			}

			$columns[] = [
				'TABLE_SCHEMA' => $schemaName,
				'TABLE_NAME' => $column['TABLE_NAME'],
				'COLUMN_NAME' => $column['COLUMN_NAME'],
				'COLUMN_TYPE' => $column['COLUMN_TYPE'],
			];
		}

		usort(
			$columns,
			static fn (array $a, array $b): int => $a['TABLE_NAME'] !== $b['TABLE_NAME']
					? strcmp($a['TABLE_NAME'], $b['TABLE_NAME'])
					: strcmp($a['COLUMN_NAME'], $b['COLUMN_NAME']),
		);

		return $columns;
	}

}

<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Report\AnalysisResult;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;
use function in_array;
use function strcmp;
use function stripos;
use function strpos;
use function strtolower;
use function usort;

final class BoolLikeColumnMysqlAuditor extends BoolLikeColumnAuditor
{

	private const IntTypes = ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'];

	public function analyse(): AnalysisResult
	{
		$profiler = $this->schema->getDataProfiler();
		$db = $this->schema->getDatabaseDefault()['name'];
		$columnsByTable = $this->schema->getColumnsByTable();

		$candidates = [];
		foreach ($this->schema->getTables() as $tableRow) {
			$table = $tableRow['TABLE_NAME'];

			$profile = null;
			foreach ($columnsByTable[$table] ?? [] as $column) {
				if (!in_array(strtolower($column['DATA_TYPE']), self::IntTypes, true)) {
					continue;
				}

				$profile ??= $profiler->getProfile($table);
				if ($profile === null || $profile['rowCount'] === 0) {
					break;
				}

				$name = $column['COLUMN_NAME'];
				// Bool-like = every non-NULL value is 0 or 1 AND at least one non-NULL value exists;
				// an all-NULL column is not bool-like.
				if ($profile['nonBool'][$name] !== 0 || $profile['nonNull'][$name] === 0) {
					continue;
				}

				$candidates[] = [$table, $name, $column];
			}
		}

		usort(
			$candidates,
			static function (array $a, array $b): int {
				if ($a[0] !== $b[0]) {
					return strcmp($a[0], $b[0]);
				}

				return strcmp($a[1], $b[1]);
			},
		);

		$checksByTable = $this->getChecksByTable($candidates);

		$violations = [];
		foreach ($candidates as [$table, $name, $column]) {
			$source = new ColumnViolationSource($db, null, $table, $name);
			$source->setColumnType($column['COLUMN_TYPE']);

			if (strtolower($column['DATA_TYPE']) !== 'tinyint') {
				$violations[] = new Violation(
					'bool_like_column',
					'Column '
					. $source->toString()
					. ' contains only 0 and 1 but is not defined as tinyint.',
					$source,
				);
			}

			$hasBooleanCheck = false;
			foreach ($checksByTable[$table] ?? [] as $clause) {
				if (strpos($clause, '`' . $name . '`') === false) {
					continue;
				}

				// MySQL and MariaDB normalise the IN keyword's case differently, so match case-insensitively.
				if (
					stripos($clause, "`{$name}` in (0,1)") !== false
					|| stripos($clause, "`{$name}` in (1,0)") !== false
				) {
					$hasBooleanCheck = true;

					break;
				}
			}

			if (!$hasBooleanCheck) {
				$violations[] = new Violation(
					'bool_like_column',
					'Column '
					. $source->toString()
					. " contains only 0 and 1 but the table does not define CHECK ( `{$name}` IN (0, 1)).",
					$source,
				);
			}
		}

		return new AnalysisResult($violations);
	}

	/**
	 * @param list<array{0: string, 1: string, 2: array<string, mixed>}> $candidates
	 * @return array<string, list<string>>
	 */
	private function getChecksByTable(array $candidates): array
	{
		if ($candidates === []) {
			return [];
		}

		$tables = [];
		foreach ($candidates as [$table]) {
			$tables[$table] = true;
		}

		$list = '';
		foreach ($tables as $table => $true) {
			if ($list !== '') {
				$list .= ', ';
			}

			$list .= $this->dbal->escapeString((string) $table);
		}

		$sql = 'SELECT tc.TABLE_NAME, cc.CHECK_CLAUSE'
			. ' FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc'
			. ' JOIN INFORMATION_SCHEMA.CHECK_CONSTRAINTS cc'
			. ' ON tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME AND tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA'
			. " WHERE tc.CONSTRAINT_TYPE = 'CHECK' AND tc.TABLE_SCHEMA = DATABASE()"
			. ' AND tc.TABLE_NAME IN (' . $list . ')';

		// phpcs:ignore SlevomatCodingStandard.PHP.RequireExplicitAssertion.RequiredExplicitAssertion
		/** @var literal-string $literalSql */
		$literalSql = $sql;

		$checks = [];
		foreach ($this->dbal->query($literalSql) as $row) {
			$checks[$row['TABLE_NAME']][] = (string) $row['CHECK_CLAUSE'];
		}

		return $checks;
	}

}

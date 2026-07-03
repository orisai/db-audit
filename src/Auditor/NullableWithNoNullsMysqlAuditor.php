<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Change\ColumnTargetChange;
use Orisai\DbAudit\Report\AnalysisResult;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;
use function is_string;
use function strcmp;
use function usort;

final class NullableWithNoNullsMysqlAuditor extends NullableWithNoNullsAuditor
{

	public function analyse(): AnalysisResult
	{
		$profiler = $this->schema->getDataProfiler();
		$db = $this->schema->getDatabaseDefault()['name'];
		$columnsByTable = $this->schema->getColumnsByTable();

		$entries = [];
		foreach ($this->schema->getTables() as $tableRow) {
			$table = $tableRow['TABLE_NAME'];

			$profile = null;
			foreach ($columnsByTable[$table] ?? [] as $column) {
				if ($column['IS_NULLABLE'] !== 'YES') {
					continue;
				}

				$profile ??= $profiler->getProfile($table);
				if ($profile === null || $profile['rowCount'] === 0) {
					break;
				}

				$name = $column['COLUMN_NAME'];
				if ($profile['nonNull'][$name] !== $profile['rowCount']) {
					continue;
				}

				$entries[] = [$table, $name, $column];
			}
		}

		usort(
			$entries,
			static function (array $a, array $b): int {
				if ($a[0] !== $b[0]) {
					return strcmp($a[0], $b[0]);
				}

				return strcmp($a[1], $b[1]);
			},
		);

		$violations = [];
		foreach ($entries as [$table, $name, $column]) {
			$source = new ColumnViolationSource($db, null, $table, $name);
			$change = $this->planFix($db, $table, $name, $column);

			$violations[] = new Violation(
				'nullable_with_no_nulls',
				'Column '
				. $source->toString()
				. ' is nullable but contains no nulls.',
				$source,
				$change !== null,
				$change !== null ? 'Tighten the column to NOT NULL.' : null,
				$change !== null ? [$change] : [],
			);
		}

		return new AnalysisResult($violations);
	}

	/**
	 * The tightening is emitted only for plain columns — no explicit default and no extra attribute
	 * (auto_increment, generated, ON UPDATE); anything else stays a non-fixable finding. The change is a single
	 * `setNullable(false)` delta: the type, charset/collation and comment come from the current definition via the
	 * planner's merge, so tightening a commented column no longer drops its comment.
	 *
	 * @param array<string, mixed> $detail
	 */
	private function planFix(string $database, string $table, string $column, array $detail): ?ColumnTargetChange
	{
		$type = $detail['COLUMN_TYPE'] ?? null;
		$extra = $detail['EXTRA'] ?? null;
		// MariaDB reports a defaultless nullable column's COLUMN_DEFAULT as the string 'NULL', MySQL as real null;
		// both mean "no default to preserve". Any other value is an explicit default -> not safe to tighten, skip.
		$default = $detail['COLUMN_DEFAULT'] ?? null;
		$hasExplicitDefault = $default !== null && $default !== 'NULL';
		if ($hasExplicitDefault || $extra !== '' || !is_string($type)) {
			return null;
		}

		return ColumnTargetChange::forColumn($database, $table, $column)->setNullable(false);
	}

}

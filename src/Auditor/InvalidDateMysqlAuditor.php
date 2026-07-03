<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Report\AnalysisResult;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;
use function in_array;
use function strcmp;
use function strtolower;
use function usort;

final class InvalidDateMysqlAuditor extends InvalidDateAuditor
{

	private const DateTypes = ['date', 'datetime', 'timestamp'];

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
				if (!in_array(strtolower($column['DATA_TYPE']), self::DateTypes, true)) {
					continue;
				}

				$profile ??= $profiler->getProfile($table);
				if ($profile === null || $profile['rowCount'] === 0) {
					break;
				}

				$name = $column['COLUMN_NAME'];
				if ($profile['invalidDate'][$name] > 0) {
					$entries[] = [$table, $name];
				}
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
		foreach ($entries as [$table, $column]) {
			$source = new ColumnViolationSource($db, null, $table, $column);

			$violations[] = new Violation(
				'invalid_date',
				'Column '
				. $source->toString()
				. ' contains invalid dates.',
				$source,
			);
		}

		return new AnalysisResult($violations);
	}

}

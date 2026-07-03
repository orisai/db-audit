<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Report\AnalysisResult;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;
use function in_array;
use function strcmp;
use function strtolower;
use function usort;

final class EmptyColumnMysqlAuditor extends EmptyColumnAuditor
{

	private const StringTypes = ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext'];

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
				$isString = in_array(strtolower($column['DATA_TYPE']), self::StringTypes, true);
				if ($column['IS_NULLABLE'] !== 'YES' && !$isString) {
					continue;
				}

				$profile ??= $profiler->getProfile($table);
				// An empty table is the empty_table auditor's finding, not one violation per column.
				if ($profile === null || $profile['rowCount'] === 0) {
					break;
				}

				$name = $column['COLUMN_NAME'];
				$isEmpty = $isString
					? $profile['nonEmpty'][$name] === 0
					: $profile['nonNull'][$name] === 0;

				if ($isEmpty) {
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
				'empty_column',
				'Column '
				. $source->toString()
				. ' is empty.',
				$source,
			);
		}

		return new AnalysisResult($violations);
	}

}

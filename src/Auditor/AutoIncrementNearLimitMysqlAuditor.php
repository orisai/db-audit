<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Report\AnalysisResult;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;
use function stripos;
use function strtolower;

final class AutoIncrementNearLimitMysqlAuditor extends AutoIncrementNearLimitAuditor
{

	private const SignedMax = [
		'tinyint' => 127.0,
		'smallint' => 32_767.0,
		'mediumint' => 8_388_607.0,
		'int' => 2_147_483_647.0,
		'bigint' => 9_223_372_036_854_775_807.0,
	],
		UnsignedMax = [
			'tinyint' => 255.0,
			'smallint' => 65_535.0,
			'mediumint' => 16_777_215.0,
			'int' => 4_294_967_295.0,
			'bigint' => 18_446_744_073_709_551_615.0,
		];

	public function analyse(): AnalysisResult
	{
		//TODO - kontrolovat poslední datum analýzy a reportovat, že může být outdated
		//		- to by bylo dobré i jako samostatná kontrola, záleží na ní execution plan
		//		- https://www.percona.com/blog/correcting-mysql-inaccurate-table-statistics-for-better-execution-plan/
		//TODO - započítávat steps
		$db = $this->schema->getDatabaseDefault()['name'];

		$autoIncrementByTable = [];
		foreach ($this->schema->getTables() as $tableRow) {
			if ($tableRow['AUTO_INCREMENT'] !== null) {
				$autoIncrementByTable[$tableRow['TABLE_NAME']] = (float) $tableRow['AUTO_INCREMENT'];
			}
		}

		$violations = [];
		foreach ($this->schema->getColumns() as $column) {
			if (stripos($column['EXTRA'], 'auto_increment') === false) {
				continue;
			}

			$autoIncrement = $autoIncrementByTable[$column['TABLE_NAME']] ?? null;
			if ($autoIncrement === null) {
				continue;
			}

			$type = strtolower($column['DATA_TYPE']);
			$isUnsigned = stripos($column['COLUMN_TYPE'], 'unsigned') !== false;
			$max = $isUnsigned ? (self::UnsignedMax[$type] ?? null) : (self::SignedMax[$type] ?? null);
			if ($max === null) {
				continue;
			}

			if ($autoIncrement / $max * 100 < (float) $this->percentileThreshold) {
				continue;
			}

			$source = new ColumnViolationSource($db, null, $column['TABLE_NAME'], $column['COLUMN_NAME']);
			$source->setColumnType($column['COLUMN_TYPE']);

			$violations[] = new Violation(
				'auto_increment_near_limit',
				'Autoincrement is above threshold of '
				. $this->percentileThreshold
				. '% in '
				. $source->toString(),
				$source,
				false,
				'Migrate the column to a wider integer type (e.g. BIGINT) before it overflows.',
			);
		}

		return new AnalysisResult($violations);
	}

}

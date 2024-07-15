<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Schema;

use function str_replace;
use function stripos;
use function strtolower;

final class CurrentColumnDefinition
{

	/**
	 * @param array{
	 *     TABLE_NAME: string, COLUMN_NAME: string, COLUMN_TYPE: string, DATA_TYPE: string,
	 *     CHARACTER_SET_NAME: string|null, COLLATION_NAME: string|null,
	 *     IS_NULLABLE: string, COLUMN_DEFAULT: string|null, EXTRA: string, COLUMN_COMMENT: string,
	 *     GENERATION_EXPRESSION: string|null, CHARACTER_MAXIMUM_LENGTH: int|null, ORDINAL_POSITION: int
	 * } $columnRow
	 * @return array{
	 *     type: string,
	 *     charset: string|null,
	 *     collation: string|null,
	 *     nullable: bool,
	 *     default: array{text: string, isExpression: bool}|null,
	 *     onUpdateCurrentTimestamp: bool,
	 *     comment: string|null,
	 *     generated: array{expression: string, stored: bool}|null,
	 *     charLength: int|null
	 * }
	 */
	public static function normalize(array $columnRow, bool $isMaria): array
	{
		$dataType = strtolower($columnRow['DATA_TYPE']);
		$charLength = $dataType === 'char' || $dataType === 'varchar'
			? $columnRow['CHARACTER_MAXIMUM_LENGTH']
			: null;

		$nullable = $columnRow['IS_NULLABLE'] === 'YES';

		$generated = null;
		$generation = $columnRow['GENERATION_EXPRESSION'];
		if ($generation !== null && $generation !== '') {
			$generated = [
				'expression' => self::renderGenerationExpression($generation, $isMaria),
				'stored' => stripos($columnRow['EXTRA'], 'STORED') !== false,
			];
		}

		$default = null;
		$onUpdate = false;
		if ($generated === null) {
			$default = self::decomposeDefault($columnRow, $isMaria);
			$onUpdate = stripos($columnRow['EXTRA'], 'on update current_timestamp') !== false;
		}

		$comment = $columnRow['COLUMN_COMMENT'] !== ''
			? $columnRow['COLUMN_COMMENT']
			: null;

		return [
			'type' => $columnRow['COLUMN_TYPE'],
			'charset' => $columnRow['CHARACTER_SET_NAME'],
			'collation' => $columnRow['COLLATION_NAME'],
			'nullable' => $nullable,
			'default' => $default,
			'onUpdateCurrentTimestamp' => $onUpdate,
			'comment' => $comment,
			'generated' => $generated,
			'charLength' => $charLength,
		];
	}

	private static function renderGenerationExpression(string $generation, bool $isMaria): string
	{
		if ($isMaria) {
			return $generation;
		}

		return str_replace('\\\'', '\'', $generation);
	}

	/**
	 * @param array{
	 *     TABLE_NAME: string, COLUMN_NAME: string, COLUMN_TYPE: string, DATA_TYPE: string,
	 *     CHARACTER_SET_NAME: string|null, COLLATION_NAME: string|null,
	 *     IS_NULLABLE: string, COLUMN_DEFAULT: string|null, EXTRA: string, COLUMN_COMMENT: string,
	 *     GENERATION_EXPRESSION: string|null, CHARACTER_MAXIMUM_LENGTH: int|null, ORDINAL_POSITION: int
	 * } $column
	 * @return array{text: string, isExpression: bool}|null
	 */
	private static function decomposeDefault(array $column, bool $isMaria): ?array
	{
		$default = $column['COLUMN_DEFAULT'];
		if ($default === null) {
			return null;
		}

		// MariaDB returns COLUMN_DEFAULT as a ready-to-use SQL expression (string literals
		// are already quoted, the absence of a default is reported as the literal 'NULL').
		if ($isMaria) {
			if ($default === 'NULL') {
				return null;
			}

			return ['text' => $default, 'isExpression' => true];
		}

		// On MySQL an unquoted expression default (e.g. CURRENT_TIMESTAMP) is flagged by
		// DEFAULT_GENERATED in EXTRA. Gating on that flag — rather than a bare prefix match — keeps a
		// string column whose literal value merely begins with "CURRENT_TIMESTAMP" from being emitted
		// unquoted, which would corrupt it.
		if (stripos($column['EXTRA'], 'DEFAULT_GENERATED') !== false) {
			return ['text' => $default, 'isExpression' => true];
		}

		return ['text' => $default, 'isExpression' => false];
	}

}

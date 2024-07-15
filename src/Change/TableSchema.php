<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Change;

final class TableSchema
{

	/**
	 * @var array<string, array{type: string, charset: string|null, collation: string|null, nullable: bool, default: array{text: string, isExpression: bool}|null, onUpdateCurrentTimestamp: bool, comment: string|null, generated: array{expression: string, stored: bool}|null, charLength: int|null, dataType: string}>
	 * @readonly
	 */
	private array $columns;

	/**
	 * @var list<array{name: string, unique: bool, members: list<array{column: string, subPart: int|null}>}>
	 * @readonly
	 */
	private array $indexes;

	/** @readonly */
	private ?string $rowFormat;

	/**
	 * @param array<string, array{type: string, charset: string|null, collation: string|null, nullable: bool, default: array{text: string, isExpression: bool}|null, onUpdateCurrentTimestamp: bool, comment: string|null, generated: array{expression: string, stored: bool}|null, charLength: int|null, dataType: string}> $columns
	 * @param list<array{name: string, unique: bool, members: list<array{column: string, subPart: int|null}>}> $indexes
	 */
	public function __construct(
		array $columns,
		array $indexes,
		?string $rowFormat
	)
	{
		$this->columns = $columns;
		$this->indexes = $indexes;
		$this->rowFormat = $rowFormat;
	}

	/**
	 * @return array<string, array{type: string, charset: string|null, collation: string|null, nullable: bool, default: array{text: string, isExpression: bool}|null, onUpdateCurrentTimestamp: bool, comment: string|null, generated: array{expression: string, stored: bool}|null, charLength: int|null, dataType: string}>
	 */
	public function getColumns(): array
	{
		return $this->columns;
	}

	/**
	 * @return list<array{name: string, unique: bool, members: list<array{column: string, subPart: int|null}>}>
	 */
	public function getIndexes(): array
	{
		return $this->indexes;
	}

	public function getRowFormat(): ?string
	{
		return $this->rowFormat;
	}

}

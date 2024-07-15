<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Schema;

final class ForeignKeyConstraint
{

	/** @readonly */
	public string $name;

	/** @readonly */
	public string $table;

	/**
	 * @var list<string>
	 * @readonly
	 */
	public array $columns;

	/** @readonly */
	public string $referencedTable;

	/**
	 * @var list<string>
	 * @readonly
	 */
	public array $referencedColumns;

	/** @readonly */
	public string $updateRule;

	/** @readonly */
	public string $deleteRule;

	/**
	 * @param list<string> $columns
	 * @param list<string> $referencedColumns
	 */
	public function __construct(
		string $name,
		string $table,
		array $columns,
		string $referencedTable,
		array $referencedColumns,
		string $updateRule,
		string $deleteRule
	)
	{
		$this->name = $name;
		$this->table = $table;
		$this->columns = $columns;
		$this->referencedTable = $referencedTable;
		$this->referencedColumns = $referencedColumns;
		$this->updateRule = $updateRule;
		$this->deleteRule = $deleteRule;
	}

}

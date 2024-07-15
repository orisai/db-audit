<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Collation\Plan;

use Orisai\DbAudit\Collation\CollationTarget;

final class TableMigration
{

	/** @readonly */
	public string $table;

	/** @readonly */
	public ?CollationTarget $tableDefault;

	/**
	 * @var list<ColumnMigration>
	 * @readonly
	 */
	public array $columns;

	/**
	 * @param list<ColumnMigration> $columns
	 */
	public function __construct(
		string $table,
		?CollationTarget $tableDefault,
		array $columns
	)
	{
		$this->table = $table;
		$this->tableDefault = $tableDefault;
		$this->columns = $columns;
	}

}

<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Schema;

use Orisai\DbAudit\Collation\TableNameFilter;

final class SchemaRequest
{

	/** @readonly */
	private ColumnCharsetClass $columnCharsetClass;

	/** @readonly */
	private TableNameFilter $excludeTables;

	/** @readonly */
	private bool $needsTableMetadata;

	/** @readonly */
	private bool $includeForeignKeyRelated;

	/** @readonly */
	private bool $needsStatistics;

	public function __construct(
		ColumnCharsetClass $columnCharsetClass,
		?TableNameFilter $excludeTables = null,
		bool $includeForeignKeyRelated = false,
		bool $needsStatistics = false,
		bool $needsTableMetadata = false
	)
	{
		$this->columnCharsetClass = $columnCharsetClass;
		$this->excludeTables = $excludeTables ?? new TableNameFilter();
		$this->needsTableMetadata = $needsTableMetadata;
		$this->includeForeignKeyRelated = $includeForeignKeyRelated;
		$this->needsStatistics = $needsStatistics;
	}

	public function getColumnCharsetClass(): ColumnCharsetClass
	{
		return $this->columnCharsetClass;
	}

	public function getExcludeTables(): TableNameFilter
	{
		return $this->excludeTables;
	}

	public function needsTableMetadata(): bool
	{
		return $this->needsTableMetadata;
	}

	public function includesForeignKeyRelated(): bool
	{
		return $this->includeForeignKeyRelated;
	}

	public function needsStatistics(): bool
	{
		return $this->needsStatistics;
	}

}

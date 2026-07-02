<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Schema;

final class SchemaRequest
{

	/** @readonly */
	private ColumnCharsetClass $columnCharsetClass;

	/** @readonly */
	private TableExclude $excludeTables;

	/** @readonly */
	private bool $needsTableMetadata;

	/** @readonly */
	private bool $includeForeignKeyRelated;

	/** @readonly */
	private bool $needsStatistics;

	public function __construct(
		ColumnCharsetClass $columnCharsetClass,
		?TableExclude $excludeTables = null,
		bool $includeForeignKeyRelated = false,
		bool $needsStatistics = false,
		bool $needsTableMetadata = false
	)
	{
		$this->columnCharsetClass = $columnCharsetClass;
		$this->excludeTables = $excludeTables ?? new TableExclude();
		$this->needsTableMetadata = $needsTableMetadata;
		$this->includeForeignKeyRelated = $includeForeignKeyRelated;
		$this->needsStatistics = $needsStatistics;
	}

	public function getColumnCharsetClass(): ColumnCharsetClass
	{
		return $this->columnCharsetClass;
	}

	public function getExcludeTables(): TableExclude
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

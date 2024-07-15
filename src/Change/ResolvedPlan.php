<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Change;

use Orisai\DbAudit\Report\Violation;
use Orisai\DbAudit\Schema\ForeignKeyConstraint;

/**
 * The planner's resolved migration: per-table merged changes a {@see MigrationStrategy} renders, the foreign keys to
 * drop before and re-add after (because their columns change), whether the run must be wrapped in
 * `foreign_key_checks = 0`, the exact number of fixes composed, and any refused conflicts.
 */
final class ResolvedPlan
{

	/**
	 * @var list<TablePlan>
	 * @readonly
	 */
	private array $tables;

	/**
	 * @var list<ForeignKeyConstraint>
	 * @readonly
	 */
	private array $foreignKeys;

	/** @readonly */
	private bool $sessionWrap;

	/** @readonly */
	private int $generatedCount;

	/**
	 * @var list<Violation>
	 * @readonly
	 */
	private array $conflicts;

	/** @readonly */
	private ?DatabaseDefaultChange $databaseDefault;

	/**
	 * @var list<Violation>
	 * @readonly
	 */
	private array $refusals;

	/**
	 * @param list<TablePlan>            $tables
	 * @param list<ForeignKeyConstraint> $foreignKeys
	 * @param list<Violation>            $conflicts
	 * @param list<Violation>            $refusals
	 */
	public function __construct(
		array $tables,
		int $generatedCount,
		array $conflicts,
		array $foreignKeys = [],
		bool $sessionWrap = false,
		?DatabaseDefaultChange $databaseDefault = null,
		array $refusals = []
	)
	{
		$this->tables = $tables;
		$this->generatedCount = $generatedCount;
		$this->conflicts = $conflicts;
		$this->foreignKeys = $foreignKeys;
		$this->sessionWrap = $sessionWrap;
		$this->databaseDefault = $databaseDefault;
		$this->refusals = $refusals;
	}

	/**
	 * @return list<TablePlan>
	 */
	public function getTables(): array
	{
		return $this->tables;
	}

	/**
	 * @return list<ForeignKeyConstraint>
	 */
	public function getForeignKeys(): array
	{
		return $this->foreignKeys;
	}

	public function getSessionWrap(): bool
	{
		return $this->sessionWrap;
	}

	public function getGeneratedCount(): int
	{
		return $this->generatedCount;
	}

	/**
	 * @return list<Violation>
	 */
	public function getConflicts(): array
	{
		return $this->conflicts;
	}

	public function getDatabaseDefault(): ?DatabaseDefaultChange
	{
		return $this->databaseDefault;
	}

	/**
	 * @return list<Violation>
	 */
	public function getRefusals(): array
	{
		return $this->refusals;
	}

}

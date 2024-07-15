<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Change;

final class TablePlan
{

	/** @readonly */
	private string $table;

	/**
	 * @var list<ChangeRequest>
	 * @readonly
	 */
	private array $changes;

	/**
	 * @var list<ColumnTargetChange>
	 * @readonly
	 */
	private array $prefixAlters;

	/**
	 * @param list<ChangeRequest>       $changes
	 * @param list<ColumnTargetChange>  $prefixAlters
	 */
	public function __construct(string $table, array $changes, array $prefixAlters = [])
	{
		$this->table = $table;
		$this->changes = $changes;
		$this->prefixAlters = $prefixAlters;
	}

	public function getTable(): string
	{
		return $this->table;
	}

	/**
	 * @return list<ChangeRequest>
	 */
	public function getChanges(): array
	{
		return $this->changes;
	}

	/**
	 * @return list<ColumnTargetChange>
	 */
	public function getPrefixAlters(): array
	{
		return $this->prefixAlters;
	}

}

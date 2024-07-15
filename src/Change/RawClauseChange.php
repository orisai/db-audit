<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Change;

final class RawClauseChange implements ChangeRequest
{

	/** @readonly */
	private string $database;

	/** @readonly */
	private string $table;

	/** @readonly */
	private string $attribute;

	/** @readonly */
	private string $clause; // Rendered verbatim by the strategy's keyword boundary; pass only builder-controlled literals.

	/** @readonly */
	private int $sortKey;

	/** @readonly */
	private bool $countsAsFix;

	public function __construct(
		string $database,
		string $table,
		string $attribute,
		string $clause,
		int $sortKey,
		bool $countsAsFix = true
	)
	{
		$this->database = $database;
		$this->table = $table;
		$this->attribute = $attribute;
		$this->clause = $clause;
		$this->sortKey = $sortKey;
		$this->countsAsFix = $countsAsFix;
	}

	public function getDatabase(): string
	{
		return $this->database;
	}

	public function getTable(): string
	{
		return $this->table;
	}

	public function getAttribute(): string
	{
		return $this->attribute;
	}

	public function getComparisonKey(): string
	{
		return $this->clause;
	}

	public function getSortKey(): int
	{
		return $this->sortKey;
	}

	public function countsAsFix(): bool
	{
		return $this->countsAsFix;
	}

}

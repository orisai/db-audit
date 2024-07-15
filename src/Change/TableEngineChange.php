<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Change;

final class TableEngineChange implements ChangeRequest
{

	/** @readonly */
	private string $database;

	/** @readonly */
	private string $table;

	/** @readonly */
	private string $engine;

	public function __construct(string $database, string $table, string $engine)
	{
		$this->database = $database;
		$this->table = $table;
		$this->engine = $engine;
	}

	public function getDatabase(): string
	{
		return $this->database;
	}

	public function getTable(): string
	{
		return $this->table;
	}

	public function getEngine(): string
	{
		return $this->engine;
	}

	public function getAttribute(): string
	{
		return 'engine';
	}

	public function getComparisonKey(): string
	{
		return 'ENGINE=' . $this->engine;
	}

	public function getSortKey(): int
	{
		return 12;
	}

	public function countsAsFix(): bool
	{
		return true;
	}

}

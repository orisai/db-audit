<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Change;

final class DropIndexChange implements ChangeRequest
{

	/** @readonly */
	private string $database;

	/** @readonly */
	private string $table;

	/** @readonly */
	private string $indexName;

	/** @readonly */
	private bool $countsAsFix;

	public function __construct(string $database, string $table, string $indexName, bool $countsAsFix = true)
	{
		$this->database = $database;
		$this->table = $table;
		$this->indexName = $indexName;
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

	public function getIndexName(): string
	{
		return $this->indexName;
	}

	public function getAttribute(): string
	{
		return 'drop_index:' . $this->indexName;
	}

	public function getComparisonKey(): string
	{
		return 'DROP INDEX `' . $this->indexName . '`';
	}

	public function getSortKey(): int
	{
		return 20;
	}

	public function countsAsFix(): bool
	{
		return $this->countsAsFix;
	}

}

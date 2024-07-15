<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Change;

final class TableDefaultCollationChange implements ChangeRequest
{

	/** @readonly */
	private string $database;

	/** @readonly */
	private string $table;

	/** @readonly */
	private string $charset;

	/** @readonly */
	private string $collation;

	public function __construct(string $database, string $table, string $charset, string $collation)
	{
		$this->database = $database;
		$this->table = $table;
		$this->charset = $charset;
		$this->collation = $collation;
	}

	public function getDatabase(): string
	{
		return $this->database;
	}

	public function getTable(): string
	{
		return $this->table;
	}

	public function getCharset(): string
	{
		return $this->charset;
	}

	public function getCollation(): string
	{
		return $this->collation;
	}

	public function getAttribute(): string
	{
		return 'table_default';
	}

	public function getComparisonKey(): string
	{
		return 'DEFAULT CHARACTER SET = ' . $this->charset . ' COLLATE = ' . $this->collation;
	}

	public function getSortKey(): int
	{
		return 11;
	}

	public function countsAsFix(): bool
	{
		return true;
	}

}

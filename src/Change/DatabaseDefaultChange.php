<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Change;

/**
 * Changes a database's default charset/collation via `ALTER DATABASE`. Routed by the planner to the resolved
 * plan's database default rather than into a per-table ALTER.
 */
final class DatabaseDefaultChange implements ChangeRequest
{

	/** @readonly */
	private string $database;

	/** @readonly */
	private string $charset;

	/** @readonly */
	private string $collation;

	public function __construct(string $database, string $charset, string $collation)
	{
		$this->database = $database;
		$this->charset = $charset;
		$this->collation = $collation;
	}

	public function getDatabase(): string
	{
		return $this->database;
	}

	public function getCharset(): string
	{
		return $this->charset;
	}

	public function getCollation(): string
	{
		return $this->collation;
	}

	public function getTable(): string
	{
		return '';
	}

	public function getAttribute(): string
	{
		return 'database_default';
	}

	public function getComparisonKey(): string
	{
		return 'CHARACTER SET = ' . $this->charset . ' COLLATE = ' . $this->collation;
	}

	public function getSortKey(): int
	{
		return 0;
	}

	public function countsAsFix(): bool
	{
		return true;
	}

}

<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Report;

final class TableViolationSource implements ViolationSource
{

	private string $database;

	private ?string $schema;

	private string $table;

	public function __construct(
		string $database,
		?string $schema,
		string $table
	)
	{
		$this->database = $database;
		$this->schema = $schema;
		$this->table = $table;
	}

	public function toString(): string
	{
		return ($this->schema !== null ? "[$this->schema]" : '')
			. "[$this->table]";
	}

	public function getDatabase(): string
	{
		return $this->database;
	}

	public function getSchema(): ?string
	{
		return $this->schema;
	}

	public function getTable(): string
	{
		return $this->table;
	}

	public function __toString(): string
	{
		return $this->toString();
	}

}

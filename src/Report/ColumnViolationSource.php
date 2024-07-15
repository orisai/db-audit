<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Report;

final class ColumnViolationSource implements ViolationSource
{

	private string $database;

	private ?string $schema;

	private string $table;

	private string $column;

	private ?string $columnType = null;

	public function __construct(
		string $database,
		?string $schema,
		string $table,
		string $column
	)
	{
		$this->database = $database;
		$this->schema = $schema;
		$this->table = $table;
		$this->column = $column;
	}

	public function toString(): string
	{
		$name = ($this->schema !== null ? "[$this->schema]" : '')
			. "[$this->table]"
			. "[$this->column]";

		$extra = '';
		if ($this->columnType !== null) {
			$extra = " (Column type: '$this->columnType')";
		}

		return $name . $extra;
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

	public function getColumn(): string
	{
		return $this->column;
	}

	public function setColumnType(string $columnType): self
	{
		$this->columnType = $columnType;

		return $this;
	}

	public function getColumnType(): ?string
	{
		return $this->columnType;
	}

	public function __toString(): string
	{
		return $this->toString();
	}

}

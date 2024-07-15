<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Change;

final class SchemaContext
{

	/**
	 * @var array<string, TableSchema>
	 * @readonly
	 */
	private array $tables;

	/**
	 * @var array<string, int>
	 * @readonly
	 */
	private array $charsetMaxlen;

	/**
	 * @param array<string, TableSchema> $tables
	 * @param array<string, int> $charsetMaxlen
	 */
	public function __construct(array $tables, array $charsetMaxlen)
	{
		$this->tables = $tables;
		$this->charsetMaxlen = $charsetMaxlen;
	}

	public function getTable(string $name): ?TableSchema
	{
		return $this->tables[$name] ?? null;
	}

	public function getCharsetMaxlen(string $charset): int
	{
		return $this->charsetMaxlen[$charset] ?? 4;
	}

}

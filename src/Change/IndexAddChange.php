<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Change;

use function implode;

/**
 * Adds an index. No auditor emits this yet (it is a forward seam for a future index-add fixer); the strategy can already
 * render it.
 */
final class IndexAddChange implements ChangeRequest
{

	/** @readonly */
	private string $database;

	/** @readonly */
	private string $table;

	/** @readonly */
	private string $indexName;

	/** @readonly */
	private bool $unique;

	/**
	 * @var list<array{column: string, subPart: int|null}>
	 * @readonly
	 */
	private array $members;

	/**
	 * @param list<array{column: string, subPart: int|null}> $members
	 */
	public function __construct(
		string $database,
		string $table,
		string $indexName,
		bool $unique,
		array $members
	)
	{
		$this->database = $database;
		$this->table = $table;
		$this->indexName = $indexName;
		$this->unique = $unique;
		$this->members = $members;
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

	public function isUnique(): bool
	{
		return $this->unique;
	}

	/**
	 * @return list<array{column: string, subPart: int|null}>
	 */
	public function getMembers(): array
	{
		return $this->members;
	}

	public function getAttribute(): string
	{
		return 'add_index:' . $this->indexName;
	}

	public function getComparisonKey(): string
	{
		$parts = [];
		foreach ($this->members as $member) {
			$part = '`' . $member['column'] . '`';
			if ($member['subPart'] !== null) {
				$part .= '(' . $member['subPart'] . ')';
			}

			$parts[] = $part;
		}

		return 'ADD ' . ($this->unique ? 'UNIQUE ' : '') . 'INDEX `' . $this->indexName . '` (' . implode(
			', ',
			$parts,
		) . ')';
	}

	public function getSortKey(): int
	{
		return 40;
	}

	public function countsAsFix(): bool
	{
		return false;
	}

}

<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Helper;

use DateTimeInterface;
use Orisai\DbAudit\Dbal\DbalAdapter;
use function strpos;

final class CountingDbalAdapter implements DbalAdapter
{

	private DbalAdapter $inner;

	private int $queryCount = 0;

	/** @var list<string> */
	private array $queries = [];

	public function __construct(DbalAdapter $inner)
	{
		$this->inner = $inner;
	}

	public function getQueryCount(): int
	{
		return $this->queryCount;
	}

	public function getQueryCountContaining(string $needle): int
	{
		$count = 0;
		foreach ($this->queries as $sql) {
			if (strpos($sql, $needle) !== false) {
				$count++;
			}
		}

		return $count;
	}

	public function query(string $sql): array
	{
		$this->queryCount++;
		$this->queries[] = $sql;

		return $this->inner->query($sql);
	}

	public function exec(string $sql): int
	{
		return $this->inner->exec($sql);
	}

	public function escapeString(string $value): string
	{
		return $this->inner->escapeString($value);
	}

	public function escapeInt(int $value): string
	{
		return $this->inner->escapeInt($value);
	}

	public function escapeBool(bool $value): string
	{
		return $this->inner->escapeBool($value);
	}

	public function escapeDateTime(DateTimeInterface $value): string
	{
		return $this->inner->escapeDateTime($value);
	}

	public function escapeIdentifier(string $value): string
	{
		return $this->inner->escapeIdentifier($value);
	}

}

<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Dbal;

use DateTimeInterface;
use Dibi\Connection;

final class DibiAdapter implements DbalAdapter
{

	private Connection $connection;

	public function __construct(Connection $connection)
	{
		$this->connection = $connection;
	}

	public function query(string $sql): array
	{
		$result = $this->connection->nativeQuery($sql);
		$result->setRowClass(null);

		return $result->fetchAll();
	}

	public function exec(string $sql): int
	{
		$this->connection->nativeQuery($sql);

		return $this->connection->getAffectedRows();
	}

	public function escapeString(string $value): string
	{
		return $this->connection->getDriver()->escapeText($value);
	}

	public function escapeInt(int $value): string
	{
		return (string) $value;
	}

	public function escapeBool(bool $value): string
	{
		return $this->connection->getDriver()->escapeBool($value);
	}

	public function escapeDateTime(DateTimeInterface $value): string
	{
		return $this->connection->getDriver()->escapeDateTime($value);
	}

	public function escapeIdentifier(string $value): string
	{
		return $this->connection->getDriver()->escapeIdentifier($value);
	}

}

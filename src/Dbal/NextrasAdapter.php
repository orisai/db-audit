<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Dbal;

use DateTimeInterface;
use Nextras\Dbal\Connection;
use Nextras\Dbal\Drivers\IDriver;
use Nextras\Dbal\Result\Row;
use function array_map;
use function iterator_to_array;
use function method_exists;

final class NextrasAdapter implements DbalAdapter
{

	private Connection $connection;

	private int $version;

	public function __construct(Connection $connection)
	{
		$this->connection = $connection;
		$this->connection->connect();

		if (method_exists($connection->getDriver(), 'convertToSql')) {
			$this->version = 1;
		} elseif (method_exists($connection->getDriver(), 'convertBoolToSql')) {
			$this->version = 2;
		} else {
			$this->version = 5;
		}
	}

	public function query(string $sql): array
	{
		return array_map(
			static fn (Row $row) => $row->toArray(),
			iterator_to_array($this->connection->query('%raw', $sql)),
		);
	}

	public function exec(string $sql): int
	{
		$this->connection->query('%raw', $sql);

		return $this->connection->getAffectedRows();
	}

	public function escapeString(string $value): string
	{
		if ($this->version >= 2) {
			return $this->connection->getDriver()->convertStringToSql($value);
		}

		return $this->connection->getDriver()->convertToSql($value, IDriver::TYPE_STRING);
	}

	public function escapeInt(int $value): string
	{
		return (string) $value;
	}

	public function escapeBool(bool $value): string
	{
		if ($this->version >= 5) {
			return $this->connection->getPlatform()->formatBool($value);
		}

		if ($this->version >= 2) {
			return $this->connection->getDriver()->convertBoolToSql($value);
		}

		return $this->connection->getDriver()->convertToSql($value, IDriver::TYPE_BOOL);
	}

	public function escapeDateTime(DateTimeInterface $value): string
	{
		if ($this->version >= 5) {
			return $this->connection->getPlatform()->formatDateTime($value);
		}

		if ($this->version >= 2) {
			return $this->connection->getDriver()->convertDateTimeToSql($value);
		}

		return $this->connection->getDriver()->convertToSql($value, IDriver::TYPE_DATETIME);
	}

	public function escapeIdentifier(string $value): string
	{
		if ($this->version >= 5) {
			return $this->connection->getPlatform()->formatIdentifier($value);
		}

		if ($this->version >= 2) {
			return $this->connection->getDriver()->convertIdentifierToSql($value);
		}

		return $this->connection->getDriver()->convertToSql($value, IDriver::TYPE_IDENTIFIER);
	}

}

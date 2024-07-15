<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Helper;

use Orisai\DbAudit\Dbal\DbalAdapter;

final class MysqlShortcuts
{

	private DbalAdapter $dbal;

	public function __construct(DbalAdapter $adapter)
	{
		$this->dbal = $adapter;
	}

	public function createDatabase(string $name): void
	{
		$name = $this->dbal->escapeIdentifier($name);
		$this->dbal->exec("CREATE DATABASE $name");
	}

	public function useDatabase(string $name): void
	{
		$name = $this->dbal->escapeIdentifier($name);
		$this->dbal->exec("USE $name");
	}

	public function dropDatabaseIfExists(string $name): void
	{
		$name = $this->dbal->escapeIdentifier($name);
		$this->dbal->exec("DROP DATABASE IF EXISTS $name");
	}

}

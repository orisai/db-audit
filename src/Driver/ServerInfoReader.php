<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Driver;

use Orisai\DbAudit\Dbal\DbalAdapter;

final class ServerInfoReader
{

	private DbalAdapter $dbal;

	public function __construct(DbalAdapter $dbal)
	{
		$this->dbal = $dbal;
	}

	public function read(): ServerInfo
	{
		$rows = $this->dbal->query('SELECT VERSION() AS version');
		$version = (string) ($rows[0]['version'] ?? '');

		return ServerInfo::fromVersionString($version);
	}

}

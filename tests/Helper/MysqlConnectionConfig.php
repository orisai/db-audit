<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Helper;

final class MysqlConnectionConfig
{

	public string $host;

	public string $user;

	public string $password;

	public int $port;

	public function __construct(
		string $host,
		string $user,
		string $password,
		int $port
	)
	{
		$this->host = $host;
		$this->user = $user;
		$this->password = $password;
		$this->port = $port;
	}

	/**
	 * @return array<mixed>
	 */
	public function toNextras(): array
	{
		return [
			'driver' => 'mysqli',
			'host' => $this->host,
			'port' => $this->port,
			'username' => $this->user,
			'password' => $this->password,
		];
	}

	/**
	 * @return array<mixed>
	 */
	public function toDibi(): array
	{
		// same options
		return $this->toNextras();
	}

}

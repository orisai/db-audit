<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Helper;

use function getenv;

final class MysqlConnectionConfig
{

	private const DefaultMysqlPort = 35_306;

	private const DefaultMariadbPort = 35_307;

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

	public static function mysqlPort(): int
	{
		return self::portFromEnv('ORISAI_DBAUDIT_MYSQL_PORT', self::DefaultMysqlPort);
	}

	public static function mariadbPort(): int
	{
		return self::portFromEnv('ORISAI_DBAUDIT_MARIADB_PORT', self::DefaultMariadbPort);
	}

	private static function portFromEnv(string $name, int $default): int
	{
		$value = getenv($name);

		return $value === false || $value === '' ? $default : (int) $value;
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
		return [
			'driver' => 'mysqli',
			'host' => $this->host,
			'port' => $this->port,
			'username' => $this->user,
			'password' => $this->password,
			'lazy' => true,
		];
	}

}

<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Helper;

use Dibi\Connection as DibiConnection;
use Generator;
use Nextras\Dbal\Connection as NextrasConnection;
use Orisai\DbAudit\Analyser;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Dbal\DibiAdapter;
use Orisai\DbAudit\Dbal\NextrasAdapter;
use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Driver\ServerInfoReader;
use PHPUnit\Framework\Assert;
use function get_class;
use function sprintf;

final class DbProvider
{

	/** @var array<string, DibiConnection|NextrasConnection> */
	private static array $connections = [];

	/**
	 * PHPUnit resolves every test method's data provider up front, so opening a fresh connection per
	 * yield would hold (test methods × 4) connections at once and exhaust the server's max_connections.
	 * Connections are instead memoised by key and reused across data-provider calls; disconnectAll()
	 * (run in each test's tearDown) closes them, and the lazy dibi/nextras drivers reconnect on next use.
	 *
	 * @return Generator<string, array{0: DbalAdapter, 1: DatabaseEngine}>
	 */
	public static function adapters(): Generator
	{
		$servers = [
			['mysql', DatabaseEngine::mysql(), MysqlConnectionConfig::mysqlPort()],
			['mariadb', DatabaseEngine::mariadb(), MysqlConnectionConfig::mariadbPort()],
		];

		foreach ($servers as [$label, $engine, $port]) {
			$config = new MysqlConnectionConfig('127.0.0.1', 'root', 'root', $port);

			$dibiKey = "$label-dibi";
			$dibi = self::$connections[$dibiKey] ?? null;
			if (!$dibi instanceof DibiConnection) {
				$dibi = new DibiConnection($config->toDibi());
				self::$connections[$dibiKey] = $dibi;
			}

			yield $dibiKey => [new DibiAdapter($dibi), $engine];

			$nextrasKey = "$label-nextras";
			$nextras = self::$connections[$nextrasKey] ?? null;
			if (!$nextras instanceof NextrasConnection) {
				$nextras = new NextrasConnection($config->toNextras());
				self::$connections[$nextrasKey] = $nextras;
			}

			yield $nextrasKey => [new NextrasAdapter($nextras), $engine];
		}
	}

	public static function skipIfUnsupported(Analyser $auditor, DbalAdapter $dbal): void
	{
		$serverInfo = (new ServerInfoReader($dbal))->read();

		foreach ($auditor->getSupportedDatabases() as $supported) {
			if ($supported->supports($serverInfo)) {
				return;
			}
		}

		Assert::markTestSkipped(sprintf(
			'%s does not support %s %s.',
			get_class($auditor),
			$serverInfo->engine->name,
			$serverInfo->version,
		));
	}

	public static function disconnectAll(): void
	{
		// The memoised connections are kept so the next test reuses the same four objects; only their
		// underlying sockets are closed. Clearing the map here would leave every tearDown after the first a
		// no-op (the data providers are resolved up front and never repopulate it), so dangling session state
		// such as SET FOREIGN_KEY_CHECKS = 0 would leak between tests sharing a connection.
		foreach (self::$connections as $connection) {
			$connection->disconnect();
		}
	}

}

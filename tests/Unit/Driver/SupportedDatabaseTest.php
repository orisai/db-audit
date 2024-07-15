<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Unit\Driver;

use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Driver\ServerInfo;
use Orisai\DbAudit\Driver\SupportedDatabase;
use PHPUnit\Framework\TestCase;

final class SupportedDatabaseTest extends TestCase
{

	public function testProperties(): void
	{
		$supported = new SupportedDatabase(DatabaseEngine::mysql(), 8, 0, 11);
		self::assertSame(DatabaseEngine::mysql(), $supported->engine);
		self::assertSame(8, $supported->major);
		self::assertSame(0, $supported->minor);
		self::assertSame(11, $supported->patch);

		$defaults = new SupportedDatabase(DatabaseEngine::mariadb(), 10);
		self::assertSame(0, $defaults->minor);
		self::assertSame(0, $defaults->patch);
	}

	public function testSupports(): void
	{
		$mysql = new SupportedDatabase(DatabaseEngine::mysql(), 8, 0);

		// Same engine, version above the minimum
		self::assertTrue($mysql->supports(ServerInfo::fromVersionString('8.4.0')));
		// Same engine, exactly the minimum
		self::assertTrue($mysql->supports(ServerInfo::fromVersionString('8.0.0')));
		// Same engine, version below the minimum
		self::assertFalse($mysql->supports(ServerInfo::fromVersionString('5.7.44')));
		// Different engine, even at a higher version
		self::assertFalse($mysql->supports(ServerInfo::fromVersionString('11.4.2-MariaDB')));
	}

	public function testSupportsMariadbMinor(): void
	{
		$maria = new SupportedDatabase(DatabaseEngine::mariadb(), 10, 11);

		self::assertTrue($maria->supports(ServerInfo::fromVersionString('10.11.6-MariaDB')));
		self::assertTrue($maria->supports(ServerInfo::fromVersionString('12.0.1-MariaDB')));
		self::assertFalse($maria->supports(ServerInfo::fromVersionString('10.6.18-MariaDB')));
		self::assertFalse($maria->supports(ServerInfo::fromVersionString('8.0.36')));
	}

}

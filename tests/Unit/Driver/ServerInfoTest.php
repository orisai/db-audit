<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Unit\Driver;

use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Driver\ServerInfo;
use PHPUnit\Framework\TestCase;

final class ServerInfoTest extends TestCase
{

	public function test(): void
	{
		$mysql = ServerInfo::fromVersionString('8.0.36');
		self::assertSame(DatabaseEngine::mysql(), $mysql->engine);
		self::assertSame(8, $mysql->major);
		self::assertSame(0, $mysql->minor);
		self::assertSame(36, $mysql->patch);

		$maria = ServerInfo::fromVersionString('11.4.2-MariaDB-1:11.4.2+maria~ubu2404');
		self::assertSame(DatabaseEngine::mariadb(), $maria->engine);
		self::assertSame(11, $maria->major);
		self::assertSame(4, $maria->minor);
		self::assertSame(2, $maria->patch);

		// MariaDB historically prefixed "5.5.5-" before the real version
		$mariaPrefixed = ServerInfo::fromVersionString('5.5.5-10.11.6-MariaDB');
		self::assertSame(DatabaseEngine::mariadb(), $mariaPrefixed->engine);
		self::assertSame(10, $mariaPrefixed->major);
		self::assertSame(11, $mariaPrefixed->minor);
		self::assertSame(6, $mariaPrefixed->patch);

		self::assertTrue($mysql->isAtLeast(8));
		self::assertTrue($mysql->isAtLeast(8, 0, 36));
		self::assertFalse($mysql->isAtLeast(8, 1));
		self::assertTrue($maria->isAtLeast(10, 10));
		self::assertFalse($maria->isAtLeast(11, 5));
	}

}

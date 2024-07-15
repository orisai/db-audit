<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Unit\Driver;

use Orisai\DbAudit\Driver\DatabaseEngine;
use PHPUnit\Framework\TestCase;
use ValueError;

final class DatabaseEngineTest extends TestCase
{

	public function test(): void
	{
		self::assertSame('mysql', DatabaseEngine::mysql()->value);
		self::assertSame('Mysql', DatabaseEngine::mysql()->name);
		self::assertSame('mariadb', DatabaseEngine::mariadb()->value);
		self::assertSame('Mariadb', DatabaseEngine::mariadb()->name);

		self::assertSame(
			[DatabaseEngine::mysql(), DatabaseEngine::mariadb()],
			DatabaseEngine::cases(),
		);

		self::assertSame(DatabaseEngine::mysql(), DatabaseEngine::from('mysql'));
		self::assertSame(DatabaseEngine::mysql(), DatabaseEngine::tryFrom('mysql'));
		self::assertNull(DatabaseEngine::tryFrom('nope'));

		$this->expectException(ValueError::class);
		DatabaseEngine::from('nope');
	}

}

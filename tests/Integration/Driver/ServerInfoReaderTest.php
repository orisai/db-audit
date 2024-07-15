<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Driver;

use Generator;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Driver\ServerInfoReader;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\DbProvider;

final class ServerInfoReaderTest extends TestCase
{

	protected function tearDown(): void
	{
		parent::tearDown();
		DbProvider::disconnectAll();
	}

	/**
	 * @dataProvider provide
	 */
	public function test(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$info = (new ServerInfoReader($dbal))->read();
		self::assertSame($engine, $info->engine);
		self::assertGreaterThanOrEqual(8, $info->major);
	}

	public function provide(): Generator
	{
		yield from DbProvider::adapters();
	}

}

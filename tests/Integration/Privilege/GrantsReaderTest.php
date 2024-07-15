<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Privilege;

use Generator;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Privilege\GrantsReader;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\DbProvider;

final class GrantsReaderTest extends TestCase
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
		$grants = (new GrantsReader($dbal))->readForCurrentUser();
		self::assertTrue($grants->has('ALTER'));
	}

	public function provide(): Generator
	{
		yield from DbProvider::adapters();
	}

}

<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Unit\Collation;

use Orisai\DbAudit\Collation\DatabaseDefaultHandling;
use PHPUnit\Framework\TestCase;
use ValueError;

final class DatabaseDefaultHandlingTest extends TestCase
{

	public function test(): void
	{
		self::assertSame('auto', DatabaseDefaultHandling::auto()->value);
		self::assertSame('Auto', DatabaseDefaultHandling::auto()->name);
		self::assertSame('skip', DatabaseDefaultHandling::skip()->value);
		self::assertSame('Skip', DatabaseDefaultHandling::skip()->name);

		self::assertSame(
			[DatabaseDefaultHandling::auto(), DatabaseDefaultHandling::skip()],
			DatabaseDefaultHandling::cases(),
		);

		self::assertSame(DatabaseDefaultHandling::auto(), DatabaseDefaultHandling::from('auto'));
		self::assertSame(DatabaseDefaultHandling::auto(), DatabaseDefaultHandling::tryFrom('auto'));
		self::assertNull(DatabaseDefaultHandling::tryFrom('nope'));

		$this->expectException(ValueError::class);
		DatabaseDefaultHandling::from('nope');
	}

}

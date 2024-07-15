<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Unit\Collation;

use Orisai\DbAudit\Collation\LegacyCharsetConversion;
use PHPUnit\Framework\TestCase;
use ValueError;

final class LegacyCharsetConversionTest extends TestCase
{

	public function test(): void
	{
		self::assertSame('report', LegacyCharsetConversion::report()->value);
		self::assertSame('Report', LegacyCharsetConversion::report()->name);
		self::assertSame('genuine', LegacyCharsetConversion::assumeGenuine()->value);
		self::assertSame('Genuine', LegacyCharsetConversion::assumeGenuine()->name);
		self::assertSame('double-encoded', LegacyCharsetConversion::assumeDoubleEncoded()->value);
		self::assertSame('DoubleEncoded', LegacyCharsetConversion::assumeDoubleEncoded()->name);

		self::assertSame(
			[
				LegacyCharsetConversion::report(),
				LegacyCharsetConversion::assumeGenuine(),
				LegacyCharsetConversion::assumeDoubleEncoded(),
			],
			LegacyCharsetConversion::cases(),
		);

		self::assertSame(
			LegacyCharsetConversion::assumeGenuine(),
			LegacyCharsetConversion::from('genuine'),
		);
		self::assertSame(
			LegacyCharsetConversion::assumeGenuine(),
			LegacyCharsetConversion::tryFrom('genuine'),
		);
		self::assertNull(LegacyCharsetConversion::tryFrom('nope'));

		$this->expectException(ValueError::class);
		LegacyCharsetConversion::from('nope');
	}

}

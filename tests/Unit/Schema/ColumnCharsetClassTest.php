<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Unit\Schema;

use Orisai\DbAudit\Schema\ColumnCharsetClass;
use PHPUnit\Framework\TestCase;
use ValueError;

final class ColumnCharsetClassTest extends TestCase
{

	public function test(): void
	{
		self::assertSame('any', ColumnCharsetClass::any()->value);
		self::assertSame('Any', ColumnCharsetClass::any()->name);
		self::assertSame('non_utf8mb4', ColumnCharsetClass::nonUtf8mb4()->value);
		self::assertSame('NonUtf8mb4', ColumnCharsetClass::nonUtf8mb4()->name);
		self::assertSame('single_byte', ColumnCharsetClass::singleByte()->value);
		self::assertSame('SingleByte', ColumnCharsetClass::singleByte()->name);

		self::assertSame(
			[ColumnCharsetClass::any(), ColumnCharsetClass::nonUtf8mb4(), ColumnCharsetClass::singleByte()],
			ColumnCharsetClass::cases(),
		);

		self::assertSame(ColumnCharsetClass::singleByte(), ColumnCharsetClass::from('single_byte'));
		self::assertSame(ColumnCharsetClass::singleByte(), ColumnCharsetClass::tryFrom('single_byte'));
		self::assertNull(ColumnCharsetClass::tryFrom('nope'));

		$this->expectException(ValueError::class);
		ColumnCharsetClass::from('nope');
	}

}

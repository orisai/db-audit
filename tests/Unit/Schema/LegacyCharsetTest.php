<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Unit\Schema;

use Orisai\DbAudit\Schema\LegacyCharset;
use PHPUnit\Framework\TestCase;

final class LegacyCharsetTest extends TestCase
{

	public function testIsLegacy(): void
	{
		self::assertFalse(LegacyCharset::isLegacy('utf8mb4'));
		self::assertFalse(LegacyCharset::isLegacy('utf8mb3'));
		self::assertFalse(LegacyCharset::isLegacy('utf8'));

		self::assertTrue(LegacyCharset::isLegacy('latin1'));
		self::assertTrue(LegacyCharset::isLegacy('gbk'));
		self::assertTrue(LegacyCharset::isLegacy('sjis'));
	}

	public function testIsSingleByte(): void
	{
		self::assertTrue(LegacyCharset::isSingleByte('latin1', 1));
		self::assertFalse(LegacyCharset::isSingleByte('gbk', 2));
		self::assertFalse(LegacyCharset::isSingleByte('utf8mb4', 4));
	}

}

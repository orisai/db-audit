<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Unit\Collation;

use Orisai\DbAudit\Collation\CollationProfile;
use PHPUnit\Framework\TestCase;

final class CollationProfileTest extends TestCase
{

	public function test(): void
	{
		$bin4 = CollationProfile::fromCollationName('utf8mb4_bin');
		self::assertNotNull($bin4);
		self::assertTrue($bin4->isBinary);

		$bin3 = CollationProfile::fromCollationName('utf8mb3_bin');
		self::assertNotNull($bin3);
		self::assertTrue($bin3->isBinary);

		// Implicit _ci => case-insensitive, accent-sensitive
		$czech = CollationProfile::fromCollationName('utf8mb3_czech_ci');
		self::assertNotNull($czech);
		self::assertFalse($czech->isBinary);
		self::assertTrue($czech->isCaseInsensitive);
		self::assertFalse($czech->isAccentInsensitive);
		self::assertTrue($czech->equals(CollationProfile::of(true, false)));

		$generalCi = CollationProfile::fromCollationName('utf8mb3_general_ci');
		self::assertNotNull($generalCi);
		self::assertTrue($generalCi->equals(CollationProfile::of(true, false)));

		$unicode520Ci = CollationProfile::fromCollationName('utf8mb4_unicode_520_ci');
		self::assertNotNull($unicode520Ci);
		self::assertTrue($unicode520Ci->equals(CollationProfile::of(true, false)));

		// Explicit markers
		$ai0900Ci = CollationProfile::fromCollationName('utf8mb4_0900_ai_ci');
		self::assertNotNull($ai0900Ci);
		self::assertTrue($ai0900Ci->equals(CollationProfile::of(true, true)));

		$as0900Ci = CollationProfile::fromCollationName('utf8mb4_0900_as_ci');
		self::assertNotNull($as0900Ci);
		self::assertTrue($as0900Ci->equals(CollationProfile::of(true, false)));

		$as0900Cs = CollationProfile::fromCollationName('utf8mb4_0900_as_cs');
		self::assertNotNull($as0900Cs);
		self::assertTrue($as0900Cs->equals(CollationProfile::of(false, false)));

		// Unclassifiable
		self::assertNull(CollationProfile::fromCollationName('weird_collation'));
	}

}

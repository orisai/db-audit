<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Unit\Collation;

use Orisai\DbAudit\Collation\CollationProfile;
use Orisai\DbAudit\Collation\CollationResolver;
use Orisai\DbAudit\Collation\CollationTargetPolicy;
use Orisai\DbAudit\Driver\ServerInfo;
use PHPUnit\Framework\TestCase;

final class CollationResolverTest extends TestCase
{

	private const Mysql8Collations = [
		'utf8mb4_bin',
		'utf8mb4_general_ci',
		'utf8mb4_unicode_ci',
		'utf8mb4_unicode_520_ci',
		'utf8mb4_czech_ci',
		'utf8mb4_0900_ai_ci',
		'utf8mb4_0900_as_ci',
		'utf8mb4_0900_as_cs',
	];

	private const Maria11Collations = [
		'utf8mb4_bin',
		'utf8mb4_general_ci',
		'utf8mb4_unicode_ci',
		'utf8mb4_unicode_520_ci',
		'utf8mb4_czech_ci',
		'utf8mb4_uca1400_ai_ci',
		'utf8mb4_uca1400_as_ci',
		'utf8mb4_uca1400_as_cs',
	];

	public function testPreserveOrderUsesNamesake(): void
	{
		$resolver = new CollationResolver(ServerInfo::fromVersionString('8.0.36'), self::Mysql8Collations);
		$profile = CollationProfile::fromCollationName('utf8mb3_czech_ci');
		self::assertNotNull($profile);

		$target = $resolver->resolve(
			'utf8mb3',
			'utf8mb3_czech_ci',
			$profile,
			CollationTargetPolicy::preserveOrder(),
		);
		self::assertNotNull($target);
		self::assertSame('utf8mb4', $target->charset);
		self::assertSame('utf8mb4_czech_ci', $target->collation);
		// utf8mb3 -> its utf8mb4 namesake keeps bytes and collation algorithm, so ordering is preserved.
		self::assertTrue($target->orderPreserving);
	}

	public function testPreserveOrderBinary(): void
	{
		$resolver = new CollationResolver(ServerInfo::fromVersionString('8.0.36'), self::Mysql8Collations);
		$profile = CollationProfile::fromCollationName('utf8mb3_bin');
		self::assertNotNull($profile);

		$target = $resolver->resolve(
			'utf8mb3',
			'utf8mb3_bin',
			$profile,
			CollationTargetPolicy::preserveOrder(),
		);
		self::assertNotNull($target);
		self::assertSame('utf8mb4_bin', $target->collation);
		// utf8mb3_bin -> utf8mb4_bin is the namesake, bytes preserved -> order-preserving.
		self::assertTrue($target->orderPreserving);
	}

	public function testPreserveOrderLatin1BinNotOrderPreserving(): void
	{
		$resolver = new CollationResolver(ServerInfo::fromVersionString('8.0.36'), self::Mysql8Collations);
		$profile = CollationProfile::fromCollationName('latin1_bin');
		self::assertNotNull($profile);

		$target = $resolver->resolve(
			'latin1',
			'latin1_bin',
			$profile,
			CollationTargetPolicy::preserveOrder(),
		);
		self::assertNotNull($target);
		self::assertSame('utf8mb4_bin', $target->collation);
		// latin1 bytes are re-encoded to utf8mb4, so even the bin target is NOT order-preserving.
		self::assertFalse($target->orderPreserving);
	}

	public function testPreserveOrderLatin1FallsBack(): void
	{
		$resolver = new CollationResolver(ServerInfo::fromVersionString('8.0.36'), self::Mysql8Collations);
		$profile = CollationProfile::fromCollationName('latin1_swedish_ci');
		self::assertNotNull($profile);

		$target = $resolver->resolve(
			'latin1',
			'latin1_swedish_ci',
			$profile,
			CollationTargetPolicy::preserveOrder(),
		);
		// No utf8mb4_swedish_ci namesake -> conventional utf8mb4_general_ci
		self::assertNotNull($target);
		self::assertSame('utf8mb4_general_ci', $target->collation);
		// latin1 is re-encoded and the target is not the namesake -> not order-preserving.
		self::assertFalse($target->orderPreserving);
	}

	public function testPreserveOrderCaseSensitiveLegacyFallsBackToBin(): void
	{
		$resolver = new CollationResolver(ServerInfo::fromVersionString('8.0.36'), self::Mysql8Collations);
		$profile = CollationProfile::fromCollationName('latin1_general_cs');
		self::assertNotNull($profile);

		$target = $resolver->resolve(
			'latin1',
			'latin1_general_cs',
			$profile,
			CollationTargetPolicy::preserveOrder(),
		);
		self::assertNotNull($target);
		self::assertSame('utf8mb4_bin', $target->collation);
	}

	public function testModernizeMysql(): void
	{
		$resolver = new CollationResolver(ServerInfo::fromVersionString('8.0.36'), self::Mysql8Collations);
		$profile = CollationProfile::fromCollationName('utf8mb3_czech_ci');
		self::assertNotNull($profile);

		$target = $resolver->resolve(
			'utf8mb3',
			'utf8mb3_czech_ci',
			$profile,
			CollationTargetPolicy::modernize(),
		);
		self::assertNotNull($target);
		self::assertSame('utf8mb4_0900_as_ci', $target->collation);
		// modernize picks a different collation algorithm (0900), so ordering changes -> not preserving.
		self::assertFalse($target->orderPreserving);
	}

	public function testModernizeMariadb(): void
	{
		$resolver = new CollationResolver(ServerInfo::fromVersionString('11.4.2-MariaDB'), self::Maria11Collations);
		$profile = CollationProfile::fromCollationName('utf8mb3_czech_ci');
		self::assertNotNull($profile);

		$target = $resolver->resolve(
			'utf8mb3',
			'utf8mb3_czech_ci',
			$profile,
			CollationTargetPolicy::modernize(),
		);
		self::assertNotNull($target);
		self::assertSame('utf8mb4_uca1400_as_ci', $target->collation);
		// modernize picks a different collation algorithm (uca1400), so ordering changes.
		self::assertFalse($target->orderPreserving);
	}

	public function testModernizeFallsBackWhenModernAbsent(): void
	{
		// Older server without 0900/uca1400 collations
		$resolver = new CollationResolver(
			ServerInfo::fromVersionString('5.7.40'),
			['utf8mb4_bin', 'utf8mb4_general_ci', 'utf8mb4_unicode_ci', 'utf8mb4_unicode_520_ci'],
		);
		$profile = CollationProfile::fromCollationName('utf8mb3_czech_ci');
		self::assertNotNull($profile);

		$target = $resolver->resolve(
			'utf8mb3',
			'utf8mb3_czech_ci',
			$profile,
			CollationTargetPolicy::modernize(),
		);
		self::assertNotNull($target);
		self::assertSame('utf8mb4_unicode_520_ci', $target->collation);
		// modernize fallback (520) still changes the collation algorithm -> not order-preserving.
		self::assertFalse($target->orderPreserving);
	}

}

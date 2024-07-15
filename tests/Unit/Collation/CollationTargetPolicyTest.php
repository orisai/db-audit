<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Unit\Collation;

use Orisai\DbAudit\Collation\CollationTargetPolicy;
use PHPUnit\Framework\TestCase;
use ValueError;

final class CollationTargetPolicyTest extends TestCase
{

	public function test(): void
	{
		self::assertSame('preserve_order', CollationTargetPolicy::preserveOrder()->value);
		self::assertSame('PreserveOrder', CollationTargetPolicy::preserveOrder()->name);
		self::assertSame('modernize', CollationTargetPolicy::modernize()->value);
		self::assertSame('Modernize', CollationTargetPolicy::modernize()->name);

		self::assertSame(
			[CollationTargetPolicy::preserveOrder(), CollationTargetPolicy::modernize()],
			CollationTargetPolicy::cases(),
		);

		self::assertSame(CollationTargetPolicy::preserveOrder(), CollationTargetPolicy::from('preserve_order'));
		self::assertSame(CollationTargetPolicy::preserveOrder(), CollationTargetPolicy::tryFrom('preserve_order'));
		self::assertNull(CollationTargetPolicy::tryFrom('nope'));

		$this->expectException(ValueError::class);
		CollationTargetPolicy::from('nope');
	}

}

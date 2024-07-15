<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Unit;

use Orisai\DbAudit\AnalyserCategory;
use PHPUnit\Framework\TestCase;
use ValueError;

final class AnalyserCategoryTest extends TestCase
{

	public function test(): void
	{
		self::assertSame('data', AnalyserCategory::data()->value);
		self::assertSame('Data', AnalyserCategory::data()->name);
		self::assertSame('structure', AnalyserCategory::structure()->value);
		self::assertSame('Structure', AnalyserCategory::structure()->name);

		self::assertSame(
			[AnalyserCategory::data(), AnalyserCategory::structure()],
			AnalyserCategory::cases(),
		);

		self::assertSame(AnalyserCategory::data(), AnalyserCategory::from('data'));
		self::assertSame(AnalyserCategory::data(), AnalyserCategory::tryFrom('data'));
		self::assertNull(AnalyserCategory::tryFrom('nope'));

		$this->expectException(ValueError::class);
		AnalyserCategory::from('nope');
	}

}

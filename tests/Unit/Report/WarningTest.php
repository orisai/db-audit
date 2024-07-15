<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Unit\Report;

use Orisai\DbAudit\Report\Warning;
use PHPUnit\Framework\TestCase;

final class WarningTest extends TestCase
{

	public function test(): void
	{
		$warning = new Warning('unsupported', 'Some\Analyser');
		self::assertSame('unsupported', $warning->getMessage());
		self::assertSame('Some\Analyser', $warning->getAnalyser());

		$bare = new Warning('just a message');
		self::assertNull($bare->getAnalyser());
	}

}

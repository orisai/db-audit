<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Unit\Change;

use Orisai\DbAudit\Change\AlterLock;
use PHPUnit\Framework\TestCase;
use ValueError;

final class AlterLockTest extends TestCase
{

	public function test(): void
	{
		self::assertSame('default', AlterLock::default()->value);
		self::assertSame('Default', AlterLock::default()->name);
		self::assertSame('none', AlterLock::none()->value);
		self::assertSame('None', AlterLock::none()->name);
		self::assertSame('shared', AlterLock::shared()->value);
		self::assertSame('Shared', AlterLock::shared()->name);
		self::assertSame('exclusive', AlterLock::exclusive()->value);
		self::assertSame('Exclusive', AlterLock::exclusive()->name);

		self::assertSame(
			[AlterLock::default(), AlterLock::none(), AlterLock::shared(), AlterLock::exclusive()],
			AlterLock::cases(),
		);

		self::assertSame(AlterLock::shared(), AlterLock::from('shared'));
		self::assertSame(AlterLock::shared(), AlterLock::tryFrom('shared'));
		self::assertNull(AlterLock::tryFrom('nope'));

		$this->expectException(ValueError::class);
		AlterLock::from('nope');
	}

}

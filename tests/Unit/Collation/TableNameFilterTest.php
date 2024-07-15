<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Unit\Collation;

use Orisai\DbAudit\Collation\TableNameFilter;
use PHPUnit\Framework\TestCase;

final class TableNameFilterTest extends TestCase
{

	public function test(): void
	{
		$filter = (new TableNameFilter())
			->withName('cache')
			->withGlob('_*')
			->withGlob('*_log');

		self::assertTrue($filter->matches('cache'));
		self::assertTrue($filter->matches('_migrations'));
		self::assertTrue($filter->matches('audit_log'));
		self::assertFalse($filter->matches('user'));
		self::assertFalse($filter->matches('cached')); // literal name is exact

		// Empty filter matches nothing
		self::assertFalse((new TableNameFilter())->matches('anything'));
	}

}

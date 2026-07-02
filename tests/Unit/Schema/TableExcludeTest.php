<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Unit\Schema;

use Orisai\DbAudit\Schema\TableExclude;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\FakeDbalAdapter;

final class TableExcludeTest extends TestCase
{

	public function testEmpty(): void
	{
		$exclude = new TableExclude();

		self::assertTrue($exclude->isEmpty());
		self::assertFalse($exclude->matches('anything'));
		self::assertNull($exclude->sqlCondition(new FakeDbalAdapter(), 'TABLE_NAME'));
	}

	public function testWithPattern(): void
	{
		$exclude = (new TableExclude())->withPattern('^_[0-9]+$');

		self::assertFalse($exclude->isEmpty());
		self::assertTrue($exclude->matches('_10520'));
		self::assertTrue($exclude->matches('_1'));
		self::assertFalse($exclude->matches('_migrations'));
		self::assertFalse($exclude->matches('article'));
		self::assertFalse($exclude->matches('_'));
	}

	public function testMerge(): void
	{
		$a = (new TableExclude())->withPattern('^_[0-9]+$');
		$b = (new TableExclude())->withPattern('^cache$');

		$merged = $a->merge($b);
		self::assertTrue($merged->matches('_10520'));
		self::assertTrue($merged->matches('cache'));
		self::assertFalse($merged->matches('other'));

		self::assertFalse($a->matches('cache'));
		self::assertFalse($b->matches('_10520'));
	}

	public function testSqlConditionSinglePattern(): void
	{
		$exclude = (new TableExclude())->withPattern('^_[0-9]+$');

		self::assertSame(
			"(TABLE_NAME NOT REGEXP '^_[0-9]+$')",
			$exclude->sqlCondition(new FakeDbalAdapter(), 'TABLE_NAME'),
		);
	}

	public function testSqlConditionTwoPatterns(): void
	{
		$exclude = (new TableExclude())
			->withPattern('^_[0-9]+$')
			->withPattern('^cache$');

		self::assertSame(
			"(TABLE_NAME NOT REGEXP '^_[0-9]+$' AND TABLE_NAME NOT REGEXP '^cache$')",
			$exclude->sqlCondition(new FakeDbalAdapter(), 'TABLE_NAME'),
		);
	}

}

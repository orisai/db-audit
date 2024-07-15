<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Unit\Report;

use Orisai\DbAudit\Report\TableViolationSource;
use PHPUnit\Framework\TestCase;

final class TableViolationSourceTest extends TestCase
{

	public function testBasic(): void
	{
		$source = new TableViolationSource('database', null, 'table');

		self::assertSame('database', $source->getDatabase());
		self::assertNull($source->getSchema());
		self::assertSame('table', $source->getTable());

		self::assertSame(
			'[table]',
			$source->toString(),
		);
		self::assertSame(
			$source->toString(),
			(string) $source,
		);
	}

	public function testSchema(): void
	{
		$source = new TableViolationSource('database2', 'schema2', 'table2');

		self::assertSame('database2', $source->getDatabase());
		self::assertSame('schema2', $source->getSchema());
		self::assertSame('table2', $source->getTable());

		self::assertSame(
			'[schema2][table2]',
			$source->toString(),
		);
		self::assertSame(
			$source->toString(),
			(string) $source,
		);
	}

}

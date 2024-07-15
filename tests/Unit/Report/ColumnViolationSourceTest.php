<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Unit\Report;

use Orisai\DbAudit\Report\ColumnViolationSource;
use PHPUnit\Framework\TestCase;

final class ColumnViolationSourceTest extends TestCase
{

	public function testBasic(): void
	{
		$source = new ColumnViolationSource('database', null, 'table', 'column');

		self::assertSame('database', $source->getDatabase());
		self::assertNull($source->getSchema());
		self::assertSame('table', $source->getTable());
		self::assertSame('column', $source->getColumn());

		self::assertNull($source->getColumnType());

		self::assertSame(
			'[table][column]',
			$source->toString(),
		);
		self::assertSame(
			$source->toString(),
			(string) $source,
		);
	}

	public function testSchema(): void
	{
		$source = new ColumnViolationSource('database2', 'schema2', 'table2', 'column2');

		self::assertSame('database2', $source->getDatabase());
		self::assertSame('schema2', $source->getSchema());
		self::assertSame('table2', $source->getTable());
		self::assertSame('column2', $source->getColumn());

		self::assertSame(
			'[schema2][table2][column2]',
			$source->toString(),
		);
		self::assertSame(
			$source->toString(),
			(string) $source,
		);
	}

}

<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Unit\Schema;

use Orisai\DbAudit\Schema\ColumnCharsetClass;
use Orisai\DbAudit\Schema\SchemaRequest;
use Orisai\DbAudit\Schema\TableExclude;
use PHPUnit\Framework\TestCase;

final class SchemaRequestTest extends TestCase
{

	public function testGetters(): void
	{
		$exclude = (new TableExclude())->withPattern('^skip$');
		$request = new SchemaRequest(ColumnCharsetClass::nonUtf8mb4(), $exclude, true, true, true);

		self::assertSame(ColumnCharsetClass::nonUtf8mb4(), $request->getColumnCharsetClass());
		self::assertSame($exclude, $request->getExcludeTables());
		self::assertTrue($request->needsTableMetadata());
		self::assertTrue($request->includesForeignKeyRelated());
		self::assertTrue($request->needsStatistics());
	}

	public function testDefaults(): void
	{
		$request = new SchemaRequest(ColumnCharsetClass::singleByte());

		self::assertSame(ColumnCharsetClass::singleByte(), $request->getColumnCharsetClass());
		// An omitted exclude filter matches no table, so the request spans the whole database.
		self::assertFalse($request->getExcludeTables()->matches('any_table'));
		self::assertFalse($request->needsTableMetadata());
		self::assertFalse($request->includesForeignKeyRelated());
		self::assertFalse($request->needsStatistics());
	}

}

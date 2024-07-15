<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Unit\Change;

use Orisai\DbAudit\Change\SchemaContext;
use Orisai\DbAudit\Change\TableSchema;
use PHPUnit\Framework\TestCase;

final class SchemaContextTest extends TestCase
{

	public function test(): void
	{
		$column = [
			'type' => 'varchar(255)',
			'charset' => 'utf8mb3',
			'collation' => 'utf8mb3_czech_ci',
			'nullable' => false,
			'default' => null,
			'onUpdateCurrentTimestamp' => false,
			'comment' => null,
			'generated' => null,
			'charLength' => 255,
			'dataType' => 'varchar',
		];
		$table = new TableSchema(
			['name' => $column],
			[['name' => 'uq', 'unique' => true, 'members' => [['column' => 'name', 'subPart' => null]]]],
			'COMPACT',
		);
		$ctx = new SchemaContext(['city' => $table], ['utf8mb3' => 3, 'utf8mb4' => 4]);

		self::assertSame($table, $ctx->getTable('city'));
		self::assertNull($ctx->getTable('missing'));
		self::assertSame(3, $ctx->getCharsetMaxlen('utf8mb3'));
		self::assertSame(4, $ctx->getCharsetMaxlen('unknown'));
		self::assertSame('COMPACT', $table->getRowFormat());
		self::assertCount(1, $table->getIndexes());
		self::assertSame($column, $table->getColumns()['name']);
	}

}

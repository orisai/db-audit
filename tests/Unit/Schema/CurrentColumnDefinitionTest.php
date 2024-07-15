<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Unit\Schema;

use Orisai\DbAudit\Schema\CurrentColumnDefinition;
use PHPUnit\Framework\TestCase;

final class CurrentColumnDefinitionTest extends TestCase
{

	/**
	 * @return array{
	 *     TABLE_NAME: string, COLUMN_NAME: string, COLUMN_TYPE: string, DATA_TYPE: string,
	 *     CHARACTER_SET_NAME: string|null, COLLATION_NAME: string|null,
	 *     IS_NULLABLE: string, COLUMN_DEFAULT: string|null, EXTRA: string, COLUMN_COMMENT: string,
	 *     GENERATION_EXPRESSION: string|null, CHARACTER_MAXIMUM_LENGTH: int|null, ORDINAL_POSITION: int
	 * }
	 */
	private function row(
		string $columnType = 'varchar(100)',
		string $dataType = 'varchar',
		?string $characterSetName = 'utf8mb4',
		?string $collationName = 'utf8mb4_unicode_ci',
		string $isNullable = 'NO',
		?string $columnDefault = null,
		string $extra = '',
		string $columnComment = '',
		?string $generationExpression = null,
		?int $characterMaximumLength = 100
	): array
	{
		return [
			'TABLE_NAME' => 't',
			'COLUMN_NAME' => 'col',
			'COLUMN_TYPE' => $columnType,
			'DATA_TYPE' => $dataType,
			'CHARACTER_SET_NAME' => $characterSetName,
			'COLLATION_NAME' => $collationName,
			'IS_NULLABLE' => $isNullable,
			'COLUMN_DEFAULT' => $columnDefault,
			'EXTRA' => $extra,
			'COLUMN_COMMENT' => $columnComment,
			'GENERATION_EXPRESSION' => $generationExpression,
			'CHARACTER_MAXIMUM_LENGTH' => $characterMaximumLength,
			'ORDINAL_POSITION' => 1,
		];
	}

	public function testPlainVarcharNoDefault(): void
	{
		$result = CurrentColumnDefinition::normalize(
			$this->row('varchar(255)', 'varchar', 'utf8mb4', 'utf8mb4_unicode_ci', 'YES', null, '', '', null, 255),
			false,
		);

		self::assertSame('varchar(255)', $result['type']);
		self::assertSame('utf8mb4', $result['charset']);
		self::assertSame('utf8mb4_unicode_ci', $result['collation']);
		self::assertTrue($result['nullable']);
		self::assertNull($result['default']);
		self::assertFalse($result['onUpdateCurrentTimestamp']);
		self::assertNull($result['comment']);
		self::assertNull($result['generated']);
		self::assertSame(255, $result['charLength']);
	}

	public function testLiteralStringDefaultMysql(): void
	{
		$result = CurrentColumnDefinition::normalize(
			$this->row('varchar(100)', 'varchar', 'utf8mb4', 'utf8mb4_unicode_ci', 'NO', 'hello', '', '', null, 100),
			false,
		);

		self::assertNotNull($result['default']);
		self::assertSame('hello', $result['default']['text']);
		self::assertFalse($result['default']['isExpression']);
	}

	public function testLiteralStringDefaultMariaDb(): void
	{
		// MariaDB pre-quotes string defaults, so they arrive as SQL expressions.
		$result = CurrentColumnDefinition::normalize(
			$this->row('varchar(100)', 'varchar', 'utf8mb4', 'utf8mb4_unicode_ci', 'NO', "'hello'", '', '', null, 100),
			true,
		);

		self::assertNotNull($result['default']);
		self::assertSame("'hello'", $result['default']['text']);
		self::assertTrue($result['default']['isExpression']);
	}

	public function testCurrentTimestampExpressionDefaultMysql(): void
	{
		$result = CurrentColumnDefinition::normalize(
			$this->row(
				'timestamp',
				'timestamp',
				null,
				null,
				'NO',
				'CURRENT_TIMESTAMP',
				'DEFAULT_GENERATED',
				'',
				null,
				null,
			),
			false,
		);

		self::assertNotNull($result['default']);
		self::assertSame('CURRENT_TIMESTAMP', $result['default']['text']);
		self::assertTrue($result['default']['isExpression']);
		self::assertNull($result['charLength']);
	}

	public function testMariaDbNullDefault(): void
	{
		// MariaDB reports the absence of a default as the literal string 'NULL'.
		$result = CurrentColumnDefinition::normalize(
			$this->row('varchar(100)', 'varchar', 'utf8mb4', 'utf8mb4_unicode_ci', 'YES', 'NULL', '', '', null, 100),
			true,
		);

		self::assertNull($result['default']);
	}

	public function testStoredGeneratedColumnMysqlBackslashUnescape(): void
	{
		// MySQL reports generation expressions with backslash-escaped single quotes.
		$result = CurrentColumnDefinition::normalize(
			$this->row(
				'varchar(200)',
				'varchar',
				'utf8mb4',
				'utf8mb4_unicode_ci',
				'NO',
				null,
				'STORED GENERATED',
				'',
				"concat(_utf8mb3\\'x-\\',`title`)",
				200,
			),
			false,
		);

		self::assertNotNull($result['generated']);
		self::assertSame("concat(_utf8mb3'x-',`title`)", $result['generated']['expression']);
		self::assertTrue($result['generated']['stored']);
		self::assertNull($result['default']);
		self::assertFalse($result['onUpdateCurrentTimestamp']);
	}

	public function testVirtualGeneratedColumnMariaDb(): void
	{
		// MariaDB reports the expression ready-to-use; VIRTUAL is implied when STORED is absent.
		$result = CurrentColumnDefinition::normalize(
			$this->row(
				'varchar(200)',
				'varchar',
				'utf8mb4',
				'utf8mb4_unicode_ci',
				'NO',
				null,
				'VIRTUAL GENERATED',
				'',
				"concat('x-',`title`)",
				200,
			),
			true,
		);

		self::assertNotNull($result['generated']);
		self::assertSame("concat('x-',`title`)", $result['generated']['expression']);
		self::assertFalse($result['generated']['stored']);
	}

	public function testOnUpdateCurrentTimestamp(): void
	{
		$result = CurrentColumnDefinition::normalize(
			$this->row('timestamp', 'timestamp', null, null, 'NO', null, 'on update CURRENT_TIMESTAMP', '', null, null),
			false,
		);

		self::assertTrue($result['onUpdateCurrentTimestamp']);
		self::assertNull($result['generated']);
	}

	public function testComment(): void
	{
		$result = CurrentColumnDefinition::normalize(
			$this->row(
				'varchar(50)',
				'varchar',
				'utf8mb4',
				'utf8mb4_unicode_ci',
				'NO',
				null,
				'',
				'my comment',
				null,
				50,
			),
			false,
		);

		self::assertSame('my comment', $result['comment']);
	}

	public function testCharLengthOnlyForCharAndVarchar(): void
	{
		$charResult = CurrentColumnDefinition::normalize(
			$this->row('char(10)', 'char', 'utf8mb4', 'utf8mb4_unicode_ci', 'NO', null, '', '', null, 10),
			false,
		);
		self::assertSame(10, $charResult['charLength']);

		$textResult = CurrentColumnDefinition::normalize(
			$this->row('text', 'text', 'utf8mb4', 'utf8mb4_unicode_ci', 'NO', null, '', '', null, null),
			false,
		);
		self::assertNull($textResult['charLength']);
	}

}

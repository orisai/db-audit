<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Unit\Collation;

use Orisai\DbAudit\Collation\ExplicitCharsetReferenceScanner;
use PHPUnit\Framework\TestCase;

final class ExplicitCharsetReferenceScannerTest extends TestCase
{

	public function testCollateClause(): void
	{
		$scanner = new ExplicitCharsetReferenceScanner();

		self::assertSame(
			['utf8mb3_czech_ci'],
			$scanner->scan('SELECT `a` COLLATE utf8mb3_czech_ci', ['utf8mb3', 'utf8mb3_czech_ci']),
		);
	}

	public function testConvertUsing(): void
	{
		$scanner = new ExplicitCharsetReferenceScanner();

		self::assertSame(
			['utf8mb3'],
			$scanner->scan('SELECT CONVERT(`x` USING utf8mb3)', ['utf8mb3', 'utf8mb3_czech_ci']),
		);
	}

	public function testCharsetIntroducer(): void
	{
		$scanner = new ExplicitCharsetReferenceScanner();

		self::assertSame(
			['utf8mb3'],
			$scanner->scan("CONCAT(_utf8mb3'a', `x`)", ['utf8mb3', 'utf8mb3_czech_ci']),
		);
	}

	public function testCharacterSetClause(): void
	{
		$scanner = new ExplicitCharsetReferenceScanner();

		self::assertSame(
			['utf8mb3'],
			$scanner->scan('CAST(`x` AS CHAR CHARACTER SET utf8mb3)', ['utf8mb3', 'utf8mb3_czech_ci']),
		);
	}

	public function testBareToken(): void
	{
		$scanner = new ExplicitCharsetReferenceScanner();

		self::assertSame(
			['utf8mb3'],
			$scanner->scan('-- migrating utf8mb3 data', ['utf8mb3', 'utf8mb3_czech_ci']),
		);
	}

	public function testCaseInsensitive(): void
	{
		$scanner = new ExplicitCharsetReferenceScanner();

		self::assertSame(
			['utf8mb3_czech_ci'],
			$scanner->scan('SELECT `a` COLLATE UTF8MB3_CZECH_CI', ['utf8mb3_czech_ci']),
		);
	}

	public function testDistinctNames(): void
	{
		$scanner = new ExplicitCharsetReferenceScanner();

		// utf8mb3 appears twice and utf8mb3_czech_ci once; each migrated name is reported at most once.
		self::assertSame(
			['utf8mb3', 'utf8mb3_czech_ci'],
			$scanner->scan(
				"CONVERT(_utf8mb3'x' USING utf8mb3) COLLATE utf8mb3_czech_ci",
				['utf8mb3', 'utf8mb3_czech_ci'],
			),
		);
	}

	public function testDoesNotMatchUtf8mb4(): void
	{
		$scanner = new ExplicitCharsetReferenceScanner();

		// utf8mb4 contains the substring utf8mb but not the token utf8mb3, so scanning for utf8mb3 finds nothing.
		self::assertSame(
			[],
			$scanner->scan(
				'`a` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci',
				['utf8mb3', 'utf8mb3_czech_ci'],
			),
		);
	}

	public function testDoesNotMatchLongerIdentifier(): void
	{
		$scanner = new ExplicitCharsetReferenceScanner();

		// utf8mb3foo and utf8mb3_general_ci both extend the utf8mb3 token, so neither is a bare utf8mb3 hit.
		self::assertSame(
			[],
			$scanner->scan('SELECT `utf8mb3foo`, `x` COLLATE utf8mb3_general_ci', ['utf8mb3']),
		);
	}

	public function testEmptyForUnrelatedSql(): void
	{
		$scanner = new ExplicitCharsetReferenceScanner();

		self::assertSame(
			[],
			$scanner->scan('SELECT `id`, `title` FROM `article` WHERE `id` > 1', ['utf8mb3', 'utf8mb3_czech_ci']),
		);
	}

	public function testEmptyNamesAndEmptyDefinition(): void
	{
		$scanner = new ExplicitCharsetReferenceScanner();

		self::assertSame([], $scanner->scan('', ['utf8mb3']));
		self::assertSame([], $scanner->scan('COLLATE utf8mb3', []));
		self::assertSame([], $scanner->scan('COLLATE utf8mb3', ['']));
	}

}

<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Unit\Ignore;

use Orisai\DbAudit\Ignore\IgnoredError;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\TableViolationSource;
use Orisai\DbAudit\Report\Violation;
use Orisai\Exceptions\Logic\InvalidArgument;
use PHPUnit\Framework\TestCase;

final class IgnoredErrorTest extends TestCase
{

	public function testRequiresAtLeastOneCriterion(): void
	{
		$this->expectException(InvalidArgument::class);
		new IgnoredError();
	}

	public function testRawMessageAndMessageAreExclusive(): void
	{
		$this->expectException(InvalidArgument::class);
		new IgnoredError('exact', '#regex#');
	}

	public function testCountMustBePositive(): void
	{
		$this->expectException(InvalidArgument::class);
		new IgnoredError('exact', null, null, null, null, 0);
	}

	public function testRawMessage(): void
	{
		$error = new IgnoredError('Table [a] has no primary key.');
		self::assertTrue(
			$error->matchesCriteria($this->tableViolation('missing_primary_key', 'Table [a] has no primary key.', 'a')),
		);
		self::assertFalse(
			$error->matchesCriteria($this->tableViolation('missing_primary_key', 'Table [b] has no primary key.', 'b')),
		);
	}

	public function testMessageRegex(): void
	{
		$error = new IgnoredError(null, '#has no primary key#');
		self::assertTrue(
			$error->matchesCriteria($this->tableViolation('missing_primary_key', 'Table [a] has no primary key.', 'a')),
		);
		self::assertFalse($error->matchesCriteria($this->tableViolation('empty_table', 'Table [a] is empty.', 'a')));
	}

	public function testKey(): void
	{
		$error = new IgnoredError(null, null, null, null, 'missing_primary_key');
		self::assertTrue($error->matchesCriteria($this->tableViolation('missing_primary_key', 'x', 'a')));
		self::assertFalse($error->matchesCriteria($this->tableViolation('empty_table', 'x', 'a')));
	}

	public function testTable(): void
	{
		$error = new IgnoredError(null, null, 'orders');
		self::assertTrue($error->matchesCriteria($this->tableViolation('k', 'm', 'orders')));
		self::assertFalse($error->matchesCriteria($this->tableViolation('k', 'm', 'users')));
		self::assertTrue($error->matchesCriteria($this->columnViolation('k', 'm', 'orders', 'name')));
	}

	public function testColumn(): void
	{
		$error = new IgnoredError(null, null, null, 'name');
		self::assertTrue($error->matchesCriteria($this->columnViolation('k', 'm', 'orders', 'name')));
		self::assertFalse($error->matchesCriteria($this->columnViolation('k', 'm', 'orders', 'email')));
		// A table-level source has no column, so a column criterion never matches it.
		self::assertFalse($error->matchesCriteria($this->tableViolation('k', 'm', 'orders')));
	}

	public function testCriteriaAreConjunctive(): void
	{
		$error = new IgnoredError(null, null, 'orders', 'name', 'outdated_collation.column');
		self::assertTrue(
			$error->matchesCriteria($this->columnViolation('outdated_collation.column', 'm', 'orders', 'name')),
		);
		// key mismatch
		self::assertFalse($error->matchesCriteria($this->columnViolation('empty_column', 'm', 'orders', 'name')));
		// column mismatch
		self::assertFalse(
			$error->matchesCriteria($this->columnViolation('outdated_collation.column', 'm', 'orders', 'email')),
		);
	}

	private function tableViolation(string $key, string $message, string $table): Violation
	{
		/** @var literal-string $key */
		return new Violation($key, $message, new TableViolationSource('db', null, $table));
	}

	private function columnViolation(string $key, string $message, string $table, string $column): Violation
	{
		/** @var literal-string $key */
		return new Violation($key, $message, new ColumnViolationSource('db', null, $table, $column));
	}

}

<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Unit\Ignore;

use Orisai\DbAudit\Ignore\IgnoredError;
use Orisai\DbAudit\Ignore\IgnoreList;
use Orisai\DbAudit\Report\TableViolationSource;
use Orisai\DbAudit\Report\Violation;
use PHPUnit\Framework\TestCase;

final class IgnoreListTest extends TestCase
{

	public function testEmptyListKeepsEverything(): void
	{
		$violations = [$this->violation('empty_table', 'a'), $this->violation('empty_table', 'b')];
		$result = (new IgnoreList())->apply($violations);

		self::assertSame($violations, $result->getRemaining());
		self::assertSame(0, $result->getIgnoredCount());
		self::assertSame([], $result->getUnmatched());
	}

	public function testUnlimitedCountIgnoresAllMatches(): void
	{
		$result = (new IgnoreList([new IgnoredError(null, null, null, null, 'empty_table')]))->apply([
			$this->violation('empty_table', 'a'),
			$this->violation('empty_table', 'b'),
			$this->violation('missing_primary_key', 'c'),
		]);

		self::assertCount(1, $result->getRemaining());
		self::assertSame('missing_primary_key', $result->getRemaining()[0]->getKey());
		self::assertSame(2, $result->getIgnoredCount());
		self::assertSame([], $result->getUnmatched());
	}

	public function testCountIgnoresFirstNAndPrintsTheRest(): void
	{
		$a = $this->violation('empty_table', 'a');
		$b = $this->violation('empty_table', 'b');
		$c = $this->violation('empty_table', 'c');
		$result = (new IgnoreList([new IgnoredError(null, null, null, null, 'empty_table', 2)]))->apply([$a, $b, $c]);

		// First two ignored, the third printed.
		self::assertSame([$c], $result->getRemaining());
		self::assertSame(2, $result->getIgnoredCount());
		self::assertSame([], $result->getUnmatched());
	}

	public function testStaleWhenFewerThanCountMatch(): void
	{
		$entry = new IgnoredError(null, null, null, null, 'empty_table', 2);
		$result = (new IgnoreList([$entry]))->apply([$this->violation('empty_table', 'a')]);

		self::assertSame([], $result->getRemaining());
		self::assertSame(1, $result->getIgnoredCount());
		self::assertSame([$entry], $result->getUnmatched());
	}

	public function testUnmatchedWhenZeroMatch(): void
	{
		$entry = new IgnoredError(null, null, null, null, 'missing_primary_key');
		$result = (new IgnoreList([$entry]))->apply([$this->violation('empty_table', 'a')]);

		self::assertCount(1, $result->getRemaining());
		self::assertSame(0, $result->getIgnoredCount());
		self::assertSame([$entry], $result->getUnmatched());
	}

	public function testFirstMatchingEntryWithCapacityConsumes(): void
	{
		$first = new IgnoredError(null, null, null, null, 'empty_table', 1);
		$second = new IgnoredError(null, null, null, null, 'empty_table'); // unlimited
		$result = (new IgnoreList([$first, $second]))->apply([
			$this->violation('empty_table', 'a'),
			$this->violation('empty_table', 'b'),
		]);

		// first consumes one, second consumes the rest; both matched.
		self::assertSame([], $result->getRemaining());
		self::assertSame(2, $result->getIgnoredCount());
		self::assertSame([], $result->getUnmatched());
	}

	private function violation(string $key, string $table): Violation
	{
		/** @var literal-string $key */
		return new Violation($key, "message $table", new TableViolationSource('db', null, $table));
	}

}

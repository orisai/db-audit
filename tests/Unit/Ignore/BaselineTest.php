<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Unit\Ignore;

use Orisai\DbAudit\Ignore\Baseline;
use Orisai\DbAudit\Ignore\BaselineFilter;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\TableViolationSource;
use Orisai\DbAudit\Report\Violation;
use PHPUnit\Framework\TestCase;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class BaselineTest extends TestCase
{

	public function testWriteGroupsAndLoadRoundTrips(): void
	{
		$path = $this->tempPath();
		$violations = $this->sampleViolations();

		Baseline::write($path, $violations);
		$list = Baseline::load($path);

		// Two grouped entries: the duplicated column (count 2) and the table (count 1).
		self::assertCount(2, $list->getErrors());

		$result = $list->apply($violations);
		self::assertSame([], $result->getRemaining());
		self::assertSame(3, $result->getIgnoredCount());
		self::assertSame([], $result->getUnmatched());

		unlink($path);
	}

	public function testWrittenEntryCountReflectsOccurrences(): void
	{
		$path = $this->tempPath();
		Baseline::write($path, $this->sampleViolations());

		// The duplicated column groups to count 2.
		$columnEntry = null;
		foreach (Baseline::load($path)->getErrors() as $entry) {
			if ($entry->getKey() === 'outdated_collation.column') {
				$columnEntry = $entry;
			}
		}

		self::assertNotNull($columnEntry);
		self::assertSame(2, $columnEntry->getCount());
		self::assertSame('t', $columnEntry->getTable());
		self::assertSame('c', $columnEntry->getColumn());

		unlink($path);
	}

	public function testRemoveByKey(): void
	{
		$path = $this->tempPath();
		Baseline::write($path, $this->sampleViolations());

		$removed = Baseline::remove($path, new BaselineFilter('missing_primary_key'));

		self::assertSame(1, $removed);
		$remaining = Baseline::load($path)->getErrors();
		self::assertCount(1, $remaining);
		self::assertSame('outdated_collation.column', $remaining[0]->getKey());

		unlink($path);
	}

	public function testRemoveByCombination(): void
	{
		$path = $this->tempPath();
		Baseline::write($path, $this->sampleViolations());

		// count + table + column together must all match.
		$removed = Baseline::remove($path, new BaselineFilter(null, null, 2, 't', 'c'));

		self::assertSame(1, $removed);
		self::assertSame('missing_primary_key', Baseline::load($path)->getErrors()[0]->getKey());

		unlink($path);
	}

	/**
	 * @return list<Violation>
	 */
	private function sampleViolations(): array
	{
		$column = new Violation(
			'outdated_collation.column',
			'Column [t][c] has an outdated charset/collation.',
			new ColumnViolationSource('db', null, 't', 'c'),
		);

		return [
			$column,
			$column,
			new Violation(
				'missing_primary_key',
				'Table [u] has no primary key.',
				new TableViolationSource('db', null, 'u'),
			),
		];
	}

	private function tempPath(): string
	{
		$path = tempnam(sys_get_temp_dir(), 'dbaudit-baseline');
		if ($path === false) {
			self::fail('Could not create a temporary file.');
		}

		return $path;
	}

}

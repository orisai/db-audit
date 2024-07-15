<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Ignore;

use Orisai\DbAudit\Report\Violation;

final class IgnoreResult
{

	/**
	 * @var list<Violation>
	 * @readonly
	 */
	private array $remaining;

	/** @readonly */
	private int $ignoredCount;

	/**
	 * @var list<IgnoredError>
	 * @readonly
	 */
	private array $unmatched;

	/**
	 * @param list<Violation>     $remaining
	 * @param list<IgnoredError>  $unmatched
	 */
	public function __construct(array $remaining, int $ignoredCount, array $unmatched)
	{
		$this->remaining = $remaining;
		$this->ignoredCount = $ignoredCount;
		$this->unmatched = $unmatched;
	}

	/**
	 * @return list<Violation>
	 */
	public function getRemaining(): array
	{
		return $this->remaining;
	}

	public function getIgnoredCount(): int
	{
		return $this->ignoredCount;
	}

	/**
	 * @return list<IgnoredError>
	 */
	public function getUnmatched(): array
	{
		return $this->unmatched;
	}

}

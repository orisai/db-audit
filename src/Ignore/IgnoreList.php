<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Ignore;

use Orisai\DbAudit\Report\Violation;
use function array_fill;
use function count;

final class IgnoreList
{

	/**
	 * @var list<IgnoredError>
	 * @readonly
	 */
	private array $errors;

	/**
	 * @param list<IgnoredError> $errors
	 */
	public function __construct(array $errors = [])
	{
		$this->errors = $errors;
	}

	/**
	 * @return list<IgnoredError>
	 */
	public function getErrors(): array
	{
		return $this->errors;
	}

	/**
	 * Ignores up to each entry's count (null = unlimited) of the matching violations in order, so when more
	 * violations match than an entry accounts for, the later ones are returned as remaining. Entries that match
	 * fewer violations than expected (including zero) are returned as unmatched.
	 *
	 * @param list<Violation> $violations
	 */
	public function apply(array $violations): IgnoreResult
	{
		$consumed = $this->errors === [] ? [] : array_fill(0, count($this->errors), 0);

		$remaining = [];
		foreach ($violations as $violation) {
			$ignored = false;
			foreach ($this->errors as $i => $error) {
				$count = $error->getCount();
				if (($count === null || $consumed[$i] < $count) && $error->matchesCriteria($violation)) {
					$consumed[$i]++;
					$ignored = true;

					break;
				}
			}

			if (!$ignored) {
				$remaining[] = $violation;
			}
		}

		$unmatched = [];
		foreach ($this->errors as $i => $error) {
			$count = $error->getCount();
			if ($count === null ? $consumed[$i] === 0 : $consumed[$i] < $count) {
				$unmatched[] = $error;
			}
		}

		return new IgnoreResult($remaining, count($violations) - count($remaining), $unmatched);
	}

}

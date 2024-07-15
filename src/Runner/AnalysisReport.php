<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Runner;

use Orisai\DbAudit\Ignore\IgnoredError;
use Orisai\DbAudit\Report\Violation;
use Orisai\DbAudit\Report\Warning;

final class AnalysisReport
{

	/**
	 * @var list<Violation>
	 * @readonly
	 */
	private array $errors;

	/** @readonly */
	private int $ignoredCount;

	/**
	 * @var list<Warning>
	 * @readonly
	 */
	private array $warnings;

	/**
	 * @var list<IgnoredError>
	 * @readonly
	 */
	private array $unmatchedIgnores;

	/**
	 * @param list<Violation>    $errors
	 * @param list<Warning>      $warnings
	 * @param list<IgnoredError> $unmatchedIgnores
	 */
	public function __construct(array $errors, int $ignoredCount, array $warnings, array $unmatchedIgnores)
	{
		$this->errors = $errors;
		$this->ignoredCount = $ignoredCount;
		$this->warnings = $warnings;
		$this->unmatchedIgnores = $unmatchedIgnores;
	}

	/**
	 * @return list<Violation>
	 */
	public function getErrors(): array
	{
		return $this->errors;
	}

	public function getIgnoredCount(): int
	{
		return $this->ignoredCount;
	}

	/**
	 * @return list<Warning>
	 */
	public function getWarnings(): array
	{
		return $this->warnings;
	}

	/**
	 * @return list<IgnoredError>
	 */
	public function getUnmatchedIgnores(): array
	{
		return $this->unmatchedIgnores;
	}

	public function hasErrors(): bool
	{
		return $this->errors !== [] || $this->unmatchedIgnores !== [];
	}

}

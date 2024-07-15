<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Runner;

use Orisai\DbAudit\Report\Advisory;
use Orisai\DbAudit\Report\Violation;
use Orisai\DbAudit\Report\Warning;

final class GenerationReport
{

	/**
	 * @var literal-string
	 * @readonly
	 */
	private string $sql;

	/** @readonly */
	private int $generatedCount;

	/**
	 * @var list<Violation>
	 * @readonly
	 */
	private array $unfixable;

	/**
	 * @var list<Advisory>
	 * @readonly
	 */
	private array $advisories;

	/**
	 * @var list<Warning>
	 * @readonly
	 */
	private array $warnings;

	/**
	 * @param literal-string  $sql
	 * @param list<Violation> $unfixable
	 * @param list<Advisory>  $advisories
	 * @param list<Warning>   $warnings
	 */
	public function __construct(string $sql, int $generatedCount, array $unfixable, array $advisories, array $warnings)
	{
		$this->sql = $sql;
		$this->generatedCount = $generatedCount;
		$this->unfixable = $unfixable;
		$this->advisories = $advisories;
		$this->warnings = $warnings;
	}

	/**
	 * @return literal-string
	 */
	public function getSql(): string
	{
		return $this->sql;
	}

	public function getGeneratedCount(): int
	{
		return $this->generatedCount;
	}

	/**
	 * @return list<Violation>
	 */
	public function getUnfixable(): array
	{
		return $this->unfixable;
	}

	/**
	 * @return list<Advisory>
	 */
	public function getAdvisories(): array
	{
		return $this->advisories;
	}

	/**
	 * @return list<Warning>
	 */
	public function getWarnings(): array
	{
		return $this->warnings;
	}

	public function hasUnfixable(): bool
	{
		return $this->unfixable !== [];
	}

}

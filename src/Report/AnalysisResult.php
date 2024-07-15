<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Report;

final class AnalysisResult
{

	/**
	 * @var list<Violation>
	 * @readonly
	 */
	private array $violations;

	/**
	 * @var list<Advisory>
	 * @readonly
	 */
	private array $advisories;

	/**
	 * @param list<Violation> $violations
	 * @param list<Advisory>  $advisories
	 */
	public function __construct(array $violations, array $advisories = [])
	{
		$this->violations = $violations;
		$this->advisories = $advisories;
	}

	/**
	 * @return list<Violation>
	 */
	public function getViolations(): array
	{
		return $this->violations;
	}

	/**
	 * @return list<Advisory>
	 */
	public function getAdvisories(): array
	{
		return $this->advisories;
	}

}

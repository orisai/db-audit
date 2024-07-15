<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Analyser;
use Orisai\DbAudit\AnalyserCategory;
use Orisai\DbAudit\Dbal\DbalAdapter;

abstract class AutoIncrementNearLimitAuditor implements Analyser
{

	use MysqlFamilySupport;

	protected DbalAdapter $dbal;

	/** @var int<1, 99> */
	protected int $percentileThreshold = 90;

	public function __construct(DbalAdapter $dbal)
	{
		$this->dbal = $dbal;
	}

	/**
	 * @param int<1, 99> $percentileThreshold
	 */
	public function setPercentileThreshold(int $percentileThreshold): void
	{
		$this->percentileThreshold = $percentileThreshold;
	}

	public function getCategory(): AnalyserCategory
	{
		return AnalyserCategory::data();
	}

}

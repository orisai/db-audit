<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Analyser;
use Orisai\DbAudit\Dbal\DbalAdapter;

abstract class AutoIncrementNearLimitAuditor implements Analyser
{

	protected DbalAdapter $dbal;

	/** @var int<1, 99> */
	protected int $percentileThreshold = 90;

	public function __construct(DbalAdapter $dbal)
	{
		$this->dbal = $dbal;
	}

	public static function getKey(): string
	{
		return 'auto_increment_near_limit';
	}

	/**
	 * @param int<1, 99> $percentileThreshold
	 */
	public function setPercentileThreshold(int $percentileThreshold): void
	{
		$this->percentileThreshold = $percentileThreshold;
	}

}

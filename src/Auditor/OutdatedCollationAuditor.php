<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Analyser;
use Orisai\DbAudit\Dbal\DbalAdapter;

abstract class OutdatedCollationAuditor implements Analyser
{

	protected DbalAdapter $dbal;

	public function __construct(DbalAdapter $dbal)
	{
		$this->dbal = $dbal;
	}

	public static function getKey(): string
	{
		return 'outdated_collation';
	}

}

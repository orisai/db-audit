<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

final class OutdatedCollationMysqlAuditor extends OutdatedCollationAuditor
{

	public function analyse(): array
	{
		// TODO: Implement analyse() method.
		//		- pro začátek jednoduchý whitelist? / blacklist?
		//		- a kombinovat s character set
		//		 - chceme ideálně upgradovat na kompatibilní set
		return [];
	}

}

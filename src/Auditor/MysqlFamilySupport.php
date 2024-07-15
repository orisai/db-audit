<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Driver\SupportedDatabase;

trait MysqlFamilySupport
{

	/**
	 * @return list<SupportedDatabase>
	 */
	public function getSupportedDatabases(): array
	{
		return [
			new SupportedDatabase(DatabaseEngine::mysql(), 8, 0),
			new SupportedDatabase(DatabaseEngine::mariadb(), 10, 11),
		];
	}

}

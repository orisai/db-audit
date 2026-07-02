<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

use Orisai\DbAudit\Analyser;
use Orisai\DbAudit\AnalyserCategory;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Schema\SchemaProvider;

abstract class InvalidDateAuditor implements Analyser
{

	use MysqlFamilySupport;

	protected DbalAdapter $dbal;

	public function __construct(SchemaProvider $schema)
	{
		$this->dbal = $schema->getDbal();
	}

	public function getCategory(): AnalyserCategory
	{
		return AnalyserCategory::data();
	}

}

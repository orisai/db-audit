<?php declare(strict_types = 1);

namespace Orisai\DbAudit;

interface Generator extends Analyser
{

	public function generate(): string;

}

<?php declare(strict_types = 1);

namespace Orisai\DbAudit;

interface Fixer extends Analyser
{

	public function fix(): void;

}

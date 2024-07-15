<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Report;

interface ViolationSource
{

	public function toString(): string;

	public function __toString(): string;

}

<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Collation;

final class CollationTarget
{

	/** @readonly */
	public string $charset;

	/** @readonly */
	public string $collation;

	/** @readonly */
	public bool $orderPreserving;

	public function __construct(string $charset, string $collation, bool $orderPreserving = false)
	{
		$this->charset = $charset;
		$this->collation = $collation;
		$this->orderPreserving = $orderPreserving;
	}

}

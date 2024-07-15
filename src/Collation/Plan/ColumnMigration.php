<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Collation\Plan;

use Orisai\DbAudit\Collation\CollationTarget;

final class ColumnMigration
{

	/** @readonly */
	public string $name;

	/** @readonly */
	public string $columnType;

	/** @readonly */
	public CollationTarget $target;

	/** @readonly */
	public bool $underUniqueIndex;

	/** @readonly */
	public bool $binaryTwoStep;

	// Post-migration char length for char/varchar (drives FK length alignment and key-length math); null for
	// any other type, where it must stay null so the row-format pass falls back to the introspected length.
	/** @readonly */
	public ?int $effectiveLength;

	public function __construct(
		string $name,
		string $columnType,
		CollationTarget $target,
		bool $underUniqueIndex,
		bool $binaryTwoStep,
		?int $effectiveLength = null
	)
	{
		$this->name = $name;
		$this->columnType = $columnType;
		$this->target = $target;
		$this->underUniqueIndex = $underUniqueIndex;
		$this->binaryTwoStep = $binaryTwoStep;
		$this->effectiveLength = $effectiveLength;
	}

}

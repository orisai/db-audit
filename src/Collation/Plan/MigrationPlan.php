<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Collation\Plan;

use Orisai\DbAudit\Collation\CollationTarget;
use Orisai\DbAudit\Report\Advisory;
use Orisai\DbAudit\Report\Violation;

final class MigrationPlan
{

	/** @readonly */
	public ?CollationTarget $databaseDefault;

	/** @readonly */
	public string $databaseName;

	/**
	 * @var list<TableMigration>
	 * @readonly
	 */
	public array $tables;

	/**
	 * @var list<Violation>
	 * @readonly
	 */
	public array $unfixable;

	/**
	 * @var list<Advisory>
	 * @readonly
	 */
	public array $advisories;

	/**
	 * @param list<TableMigration> $tables
	 * @param list<Violation>      $unfixable
	 * @param list<Advisory>       $advisories
	 */
	public function __construct(
		?CollationTarget $databaseDefault,
		string $databaseName,
		array $tables,
		array $unfixable,
		array $advisories = []
	)
	{
		$this->databaseDefault = $databaseDefault;
		$this->databaseName = $databaseName;
		$this->tables = $tables;
		$this->unfixable = $unfixable;
		$this->advisories = $advisories;
	}

	public function isEmpty(): bool
	{
		return $this->databaseDefault === null && $this->tables === [];
	}

}

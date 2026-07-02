<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Collation;

use Orisai\DbAudit\Schema\TableExclude;

final class OutdatedCollationConfig
{

	private CollationTargetPolicy $targetPolicy;

	private bool $convertUtf8mb3;

	private LegacyCharsetConversion $legacyCharsetConversion;

	private DatabaseDefaultHandling $databaseDefault;

	private ?string $executionAccount;

	private bool $forceUniqueIndexConversion = false;

	private TableExclude $excludeTables;

	public function __construct()
	{
		$this->targetPolicy = CollationTargetPolicy::preserveOrder();
		$this->convertUtf8mb3 = true;
		$this->legacyCharsetConversion = LegacyCharsetConversion::report();
		$this->databaseDefault = DatabaseDefaultHandling::auto();
		$this->executionAccount = null;
		$this->excludeTables = new TableExclude();
	}

	public function getTargetPolicy(): CollationTargetPolicy
	{
		return $this->targetPolicy;
	}

	public function setTargetPolicy(CollationTargetPolicy $targetPolicy): self
	{
		$this->targetPolicy = $targetPolicy;

		return $this;
	}

	public function convertsUtf8mb3(): bool
	{
		return $this->convertUtf8mb3;
	}

	public function setConvertUtf8mb3(bool $convertUtf8mb3): self
	{
		$this->convertUtf8mb3 = $convertUtf8mb3;

		return $this;
	}

	public function getLegacyCharsetConversion(): LegacyCharsetConversion
	{
		return $this->legacyCharsetConversion;
	}

	public function setLegacyCharsetConversion(LegacyCharsetConversion $legacyCharsetConversion): self
	{
		$this->legacyCharsetConversion = $legacyCharsetConversion;

		return $this;
	}

	public function getDatabaseDefault(): DatabaseDefaultHandling
	{
		return $this->databaseDefault;
	}

	public function setDatabaseDefault(DatabaseDefaultHandling $databaseDefault): self
	{
		$this->databaseDefault = $databaseDefault;

		return $this;
	}

	public function getExecutionAccount(): ?string
	{
		return $this->executionAccount;
	}

	public function setExecutionAccount(?string $executionAccount): self
	{
		$this->executionAccount = $executionAccount;

		return $this;
	}

	public function forcesUniqueIndexConversion(): bool
	{
		return $this->forceUniqueIndexConversion;
	}

	public function setForceUniqueIndexConversion(bool $force): self
	{
		$this->forceUniqueIndexConversion = $force;

		return $this;
	}

	public function getExcludeTables(): TableExclude
	{
		return $this->excludeTables;
	}

	public function setExcludeTables(TableExclude $excludeTables): self
	{
		$this->excludeTables = $excludeTables;

		return $this;
	}

}

<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

final class ForeignKeyColumnNameMismatchConfig
{

	private string $pattern;

	public function __construct()
	{
		$this->pattern = '/_id$/';
	}

	public function getPattern(): string
	{
		return $this->pattern;
	}

	public function setPattern(string $pattern): self
	{
		$this->pattern = $pattern;

		return $this;
	}

}

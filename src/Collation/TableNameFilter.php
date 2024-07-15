<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Collation;

use function fnmatch;
use function in_array;

final class TableNameFilter
{

	/** @var list<string> */
	private array $names = [];

	/** @var list<string> */
	private array $globs = [];

	public function withName(string $name): self
	{
		$clone = clone $this;
		$clone->names[] = $name;

		return $clone;
	}

	public function withGlob(string $glob): self
	{
		$clone = clone $this;
		$clone->globs[] = $glob;

		return $clone;
	}

	public function isEmpty(): bool
	{
		return $this->names === [] && $this->globs === [];
	}

	public function matches(string $table): bool
	{
		if (in_array($table, $this->names, true)) {
			return true;
		}

		foreach ($this->globs as $glob) {
			if (fnmatch($glob, $table)) {
				return true;
			}
		}

		return false;
	}

}

<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Schema;

use Orisai\DbAudit\Dbal\DbalAdapter;
use function preg_match;

final class TableExclude
{

	/** @var list<string> */
	private array $patterns = [];

	public function withPattern(string $pattern): self
	{
		$clone = clone $this;
		$clone->patterns[] = $pattern;

		return $clone;
	}

	public function isEmpty(): bool
	{
		return $this->patterns === [];
	}

	public function matches(string $table): bool
	{
		foreach ($this->patterns as $pattern) {
			if (preg_match('#' . $pattern . '#', $table) === 1) {
				return true;
			}
		}

		return false;
	}

	public function merge(self $other): self
	{
		$clone = clone $this;
		foreach ($other->patterns as $pattern) {
			$clone->patterns[] = $pattern;
		}

		return $clone;
	}

	/**
	 * @param literal-string $column
	 * @return literal-string|null
	 */
	public function sqlCondition(DbalAdapter $dbal, string $column): ?string
	{
		if ($this->patterns === []) {
			return null;
		}

		$condition = '';
		foreach ($this->patterns as $pattern) {
			if ($condition !== '') {
				$condition .= ' AND ';
			}

			$condition .= $column . ' NOT REGEXP ' . $dbal->escapeString($pattern);
		}

		return '(' . $condition . ')';
	}

}

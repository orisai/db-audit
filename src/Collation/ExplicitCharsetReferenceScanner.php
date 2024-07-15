<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Collation;

use function array_keys;
use function preg_match;
use function preg_quote;

final class ExplicitCharsetReferenceScanner
{

	/**
	 * @param list<string> $names
	 * @return list<string>
	 */
	public function scan(string $definition, array $names): array
	{
		$matched = [];
		foreach ($names as $name) {
			if ($name !== '' && $this->matchesToken($definition, $name)) {
				$matched[$name] = true;
			}
		}

		return array_keys($matched);
	}

	/**
	 * A name matches only as a standalone identifier token, so utf8mb3 never matches inside utf8mb4,
	 * utf8mb3foo or the collation token utf8mb3_czech_ci (the trailing-underscore lookahead rejects those).
	 * A single leading underscore is allowed so the charset-introducer form (_utf8mb3'...') still matches,
	 * and the match is case-insensitive.
	 */
	private function matchesToken(string $definition, string $name): bool
	{
		$pattern = '#(?<![A-Za-z0-9])' . preg_quote($name, '#') . '(?![A-Za-z0-9_])#i';

		return preg_match($pattern, $definition) === 1;
	}

}

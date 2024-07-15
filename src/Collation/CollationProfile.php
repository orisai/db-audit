<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Collation;

use function str_contains;
use function str_ends_with;

final class CollationProfile
{

	/** @readonly */
	public bool $isBinary;

	/** @readonly */
	public bool $isCaseInsensitive;

	/** @readonly */
	public bool $isAccentInsensitive;

	private function __construct(bool $isBinary, bool $isCaseInsensitive, bool $isAccentInsensitive)
	{
		$this->isBinary = $isBinary;
		$this->isCaseInsensitive = $isCaseInsensitive;
		$this->isAccentInsensitive = $isAccentInsensitive;
	}

	public static function binary(): self
	{
		return new self(true, false, false);
	}

	public static function of(bool $caseInsensitive, bool $accentInsensitive): self
	{
		return new self(false, $caseInsensitive, $accentInsensitive);
	}

	public static function fromCollationName(string $collation): ?self
	{
		if (str_ends_with($collation, '_bin')) {
			return self::binary();
		}

		if (str_contains($collation, '_as_cs')) {
			return self::of(false, false);
		}

		if (str_contains($collation, '_as_ci')) {
			return self::of(true, false);
		}

		if (str_contains($collation, '_ai_ci')) {
			return self::of(true, true);
		}

		if (str_contains($collation, '_ai_cs')) {
			return self::of(false, true);
		}

		if (str_ends_with($collation, '_cs')) {
			return self::of(false, false);
		}

		if (str_ends_with($collation, '_ci')) {
			return self::of(true, false);
		}

		return null;
	}

	public function equals(self $other): bool
	{
		return $this->isBinary === $other->isBinary
			&& $this->isCaseInsensitive === $other->isCaseInsensitive
			&& $this->isAccentInsensitive === $other->isAccentInsensitive;
	}

}

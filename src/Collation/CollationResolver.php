<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Collation;

use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Driver\ServerInfo;
use function in_array;
use function str_starts_with;
use function strlen;
use function substr;

final class CollationResolver
{

	private const TargetCharset = 'utf8mb4';

	/** @readonly */
	private ServerInfo $server;

	/** @var list<string> */
	private array $available;

	/**
	 * @param list<string> $availableCollations
	 */
	public function __construct(ServerInfo $server, array $availableCollations)
	{
		$this->server = $server;
		$this->available = $availableCollations;
	}

	public function resolve(
		string $sourceCharset,
		string $sourceCollation,
		CollationProfile $profile,
		CollationTargetPolicy $policy
	): ?CollationTarget
	{
		if ($profile->isBinary) {
			return $this->pick(['utf8mb4_bin'], $sourceCharset, $sourceCollation);
		}

		if ($policy === CollationTargetPolicy::preserveOrder()) {
			$namesake = $this->namesake($sourceCharset, $sourceCollation);
			if ($namesake !== null && in_array($namesake, $this->available, true)) {
				return new CollationTarget(
					self::TargetCharset,
					$namesake,
					$this->isOrderPreserving($sourceCharset, $sourceCollation, $namesake),
				);
			}

			$fallback = $profile->isCaseInsensitive ? ['utf8mb4_general_ci'] : ['utf8mb4_bin'];

			return $this->pick($fallback, $sourceCharset, $sourceCollation);
		}

		return $this->pick($this->preferenceList($profile), $sourceCharset, $sourceCollation);
	}

	/**
	 * A conversion preserves ordering/equality only when the target is the source's literal utf8mb4
	 * namesake and the source is utf8mb3/utf8 — utf8mb3 ⊂ utf8mb4, so the stored bytes are unchanged and
	 * the same collation algorithm runs over them (covers e.g. utf8mb3_czech_ci and utf8mb3_bin). A latin1
	 * (or other legacy) source is re-encoded to utf8mb4, so even a utf8mb4_bin target is not order-preserving.
	 */
	private function isOrderPreserving(string $sourceCharset, string $sourceCollation, string $targetCollation): bool
	{
		return ($sourceCharset === 'utf8mb3' || $sourceCharset === 'utf8')
			&& $targetCollation === $this->namesake($sourceCharset, $sourceCollation);
	}

	private function namesake(string $sourceCharset, string $sourceCollation): ?string
	{
		$prefix = null;
		if ($sourceCharset === 'utf8mb3' || $sourceCharset === 'utf8') {
			$prefix = $sourceCharset . '_';
		}

		if ($prefix === null || !str_starts_with($sourceCollation, $prefix)) {
			return null;
		}

		return 'utf8mb4_' . substr($sourceCollation, strlen($prefix));
	}

	/**
	 * @return list<string>
	 */
	private function preferenceList(CollationProfile $profile): array
	{
		$maria = $this->server->engine === DatabaseEngine::mariadb();

		if (!$profile->isCaseInsensitive) {
			return $maria
				? ['utf8mb4_uca1400_as_cs', 'utf8mb4_bin']
				: ['utf8mb4_0900_as_cs', 'utf8mb4_bin'];
		}

		if ($profile->isAccentInsensitive) {
			return $maria
				? ['utf8mb4_uca1400_ai_ci', 'utf8mb4_unicode_ci', 'utf8mb4_general_ci']
				: ['utf8mb4_0900_ai_ci', 'utf8mb4_unicode_ci', 'utf8mb4_general_ci'];
		}

		return $maria
			? ['utf8mb4_uca1400_as_ci', 'utf8mb4_unicode_520_ci', 'utf8mb4_unicode_ci', 'utf8mb4_general_ci']
			: ['utf8mb4_0900_as_ci', 'utf8mb4_unicode_520_ci', 'utf8mb4_unicode_ci', 'utf8mb4_general_ci'];
	}

	/**
	 * @param list<string> $candidates
	 */
	private function pick(array $candidates, string $sourceCharset, string $sourceCollation): ?CollationTarget
	{
		foreach ($candidates as $candidate) {
			if (in_array($candidate, $this->available, true)) {
				return new CollationTarget(
					self::TargetCharset,
					$candidate,
					$this->isOrderPreserving($sourceCharset, $sourceCollation, $candidate),
				);
			}
		}

		return null;
	}

}

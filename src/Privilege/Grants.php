<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Privilege;

use function array_map;
use function array_values;
use function explode;
use function in_array;
use function preg_match;
use function str_replace;
use function strtoupper;
use function trim;

final class Grants
{

	/** @var list<array{privileges: list<string>, database: string, table: string}> */
	private array $entries;

	/**
	 * @param list<array{privileges: list<string>, database: string, table: string}> $entries
	 */
	private function __construct(array $entries)
	{
		$this->entries = $entries;
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	public static function fromShowGrantsRows(array $rows): self
	{
		$entries = [];
		foreach ($rows as $row) {
			$values = array_values($row);
			$line = $values === [] ? '' : (string) $values[0];

			$entry = self::parseLine($line);
			if ($entry !== null) {
				$entries[] = $entry;
			}
		}

		return new self($entries);
	}

	/**
	 * @return array{privileges: list<string>, database: string, table: string}|null
	 */
	private static function parseLine(string $line): ?array
	{
		if (preg_match('/^GRANT\s+(.+?)\s+ON\s+(\S+)\s+TO\s/i', $line, $m) !== 1) {
			return null;
		}

		$privileges = array_map(
			static fn (string $p): string => strtoupper(trim($p)),
			explode(',', $m[1]),
		);

		$privileges = array_map(
			static fn (string $p): string => preg_match('/^([A-Z ]+)/', $p, $pm) === 1 ? trim($pm[1]) : $p,
			$privileges,
		);

		[$database, $table] = self::parseScope($m[2]);

		return [
			'privileges' => $privileges,
			'database' => $database,
			'table' => $table,
		];
	}

	/**
	 * @return array{string, string}
	 */
	private static function parseScope(string $scope): array
	{
		$clean = str_replace('`', '', $scope);
		$parts = explode('.', $clean, 2);
		$database = $parts[0];
		$table = $parts[1] ?? '*';

		return [$database, $table];
	}

	public function has(string $privilege, string $database = '*', ?string $table = null): bool
	{
		$privilege = strtoupper($privilege);
		$table ??= '*';

		foreach ($this->entries as $entry) {
			if (!self::scopeCovers($entry['database'], $entry['table'], $database, $table)) {
				continue;
			}

			if (in_array('ALL PRIVILEGES', $entry['privileges'], true)) {
				return true;
			}

			if (in_array($privilege, $entry['privileges'], true)) {
				return true;
			}
		}

		return false;
	}

	private static function scopeCovers(
		string $grantDb,
		string $grantTable,
		string $wantDb,
		string $wantTable
	): bool
	{
		if ($grantDb !== '*' && $grantDb !== $wantDb) {
			return false;
		}

		return $grantTable === '*' || $grantTable === $wantTable;
	}

}

<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Driver;

use function preg_match;
use function preg_replace;
use function str_contains;

final class ServerInfo
{

	/** @readonly */
	public DatabaseEngine $engine;

	/** @readonly */
	public int $major;

	/** @readonly */
	public int $minor;

	/** @readonly */
	public int $patch;

	/** @readonly */
	public string $version;

	public function __construct(DatabaseEngine $engine, int $major, int $minor, int $patch, string $version)
	{
		$this->engine = $engine;
		$this->major = $major;
		$this->minor = $minor;
		$this->patch = $patch;
		$this->version = $version;
	}

	public static function fromVersionString(string $version): self
	{
		$engine = str_contains($version, 'MariaDB') ? DatabaseEngine::mariadb() : DatabaseEngine::mysql();

		$normalized = $version;
		if ($engine === DatabaseEngine::mariadb() && preg_match('/^5\.5\.5-/', $normalized) === 1) {
			$normalized = (string) preg_replace('/^5\.5\.5-/', '', $normalized);
		}

		$major = 0;
		$minor = 0;
		$patch = 0;
		if (preg_match('/(\d+)\.(\d+)\.(\d+)/', $normalized, $m) === 1) {
			$major = (int) $m[1];
			$minor = (int) $m[2];
			$patch = (int) $m[3];
		}

		return new self($engine, $major, $minor, $patch, $version);
	}

	public function isAtLeast(int $major, int $minor = 0, int $patch = 0): bool
	{
		return [$this->major, $this->minor, $this->patch] >= [$major, $minor, $patch];
	}

}

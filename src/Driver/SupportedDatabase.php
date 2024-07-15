<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Driver;

final class SupportedDatabase
{

	/** @readonly */
	public DatabaseEngine $engine;

	/** @readonly */
	public int $major;

	/** @readonly */
	public int $minor;

	/** @readonly */
	public int $patch;

	public function __construct(DatabaseEngine $engine, int $major, int $minor = 0, int $patch = 0)
	{
		$this->engine = $engine;
		$this->major = $major;
		$this->minor = $minor;
		$this->patch = $patch;
	}

	public function supports(ServerInfo $serverInfo): bool
	{
		return $serverInfo->engine === $this->engine
			&& $serverInfo->isAtLeast($this->major, $this->minor, $this->patch);
	}

}

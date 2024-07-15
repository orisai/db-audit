<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Helper;

use Orisai\DbAudit\Dbal\DbalAdapter;
use function explode;
use function implode;
use function rtrim;
use function strncmp;
use function trim;

final class MysqlShortcuts
{

	private DbalAdapter $dbal;

	public function __construct(DbalAdapter $adapter)
	{
		$this->dbal = $adapter;
	}

	public function createDatabase(string $name): void
	{
		$name = $this->dbal->escapeIdentifier($name);
		$this->dbal->exec("CREATE DATABASE $name");
	}

	public function createUtf8mb3CzechDatabase(string $name): void
	{
		$name = $this->dbal->escapeIdentifier($name);
		$this->dbal->exec("CREATE DATABASE $name DEFAULT CHARACTER SET utf8mb3 COLLATE utf8mb3_czech_ci");
	}

	public function useDatabase(string $name): void
	{
		$name = $this->dbal->escapeIdentifier($name);
		$this->dbal->exec("USE $name");
	}

	public function dropDatabaseIfExists(string $name): void
	{
		$name = $this->dbal->escapeIdentifier($name);
		$this->dbal->exec("DROP DATABASE IF EXISTS $name");
	}

	/**
	 * Applies generated migration SQL by running each `;\n`-separated statement (skipping `--` comment lines).
	 */
	public function applyScript(string $sql): void
	{
		foreach (explode(";\n", $sql) as $rawStatement) {
			$lines = [];
			foreach (explode("\n", $rawStatement) as $line) {
				if (strncmp($line, '--', 2) !== 0) {
					$lines[] = $line;
				}
			}

			$statement = rtrim(trim(implode("\n", $lines)), ';');
			if ($statement !== '') {
				// phpcs:ignore SlevomatCodingStandard.PHP.RequireExplicitAssertion.RequiredExplicitAssertion
				/** @var literal-string $literalStatement */
				$literalStatement = $statement;
				$this->dbal->exec($literalStatement);
			}
		}
	}

}

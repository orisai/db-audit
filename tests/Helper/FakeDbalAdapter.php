<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Helper;

use DateTimeInterface;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\Exceptions\Logic\InvalidState;
use function str_replace;

/**
 * Standard MySQL escaping without a connection, for unit tests that render migration SQL. Byte-identical to the
 * real adapters for normal identifiers/values.
 */
final class FakeDbalAdapter implements DbalAdapter
{

	public function query(string $sql): array
	{
		throw InvalidState::create()->withMessage('FakeDbalAdapter cannot run queries.');
	}

	public function exec(string $sql): int
	{
		throw InvalidState::create()->withMessage('FakeDbalAdapter cannot run queries.');
	}

	public function escapeString(string $value): string
	{
		return $this->literal("'" . str_replace("'", "''", $value) . "'");
	}

	public function escapeInt(int $value): string
	{
		return $this->literal((string) $value);
	}

	public function escapeBool(bool $value): string
	{
		return $this->literal($value ? '1' : '0');
	}

	public function escapeDateTime(DateTimeInterface $value): string
	{
		return $this->literal("'" . $value->format('Y-m-d H:i:s') . "'");
	}

	public function escapeIdentifier(string $value): string
	{
		return $this->literal('`' . str_replace('`', '``', $value) . '`');
	}

	/**
	 * @return literal-string
	 */
	private function literal(string $sql): string
	{
		// phpcs:ignore SlevomatCodingStandard.PHP.RequireExplicitAssertion.RequiredExplicitAssertion
		/** @var literal-string $literal */
		$literal = $sql;

		return $literal;
	}

}

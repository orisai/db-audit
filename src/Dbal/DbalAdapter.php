<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Dbal;

use DateTimeInterface;
use JetBrains\PhpStorm\Language;

// phpcs:disable SlevomatCodingStandard.Classes.RequireSingleLineMethodSignature
interface DbalAdapter
{

	/**
	 * @param literal-string $sql
	 * @return list<array<string, mixed>>
	 */
	public function query(
		#[Language('GenericSQL')]
		string $sql
	): array;

	/**
	 * @param literal-string $sql
	 * @return int<0, max> number of affected rows
	 */
	public function exec(
		#[Language('GenericSQL')]
		string $sql
	): int;

	/**
	 * @return literal-string
	 */
	public function escapeString(string $value): string;

	/**
	 * @return literal-string
	 */
	public function escapeInt(int $value): string;

	/**
	 * @return literal-string
	 */
	public function escapeBool(bool $value): string;

	/**
	 * @return literal-string
	 */
	public function escapeDateTime(DateTimeInterface $value): string;

	/**
	 * @return literal-string
	 */
	public function escapeIdentifier(string $value): string;

}

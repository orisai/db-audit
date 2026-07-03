<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Ignore;

use Orisai\Exceptions\Logic\InvalidArgument;
use function preg_match;

final class BaselineFilter
{

	/** @readonly */
	private ?string $key;

	/** @readonly */
	private ?string $rawMessage;

	/** @readonly */
	private ?string $message;

	/** @readonly */
	private ?string $table;

	/** @readonly */
	private ?string $column;

	public function __construct(
		?string $key = null,
		?string $rawMessage = null,
		?string $message = null,
		?string $table = null,
		?string $column = null
	)
	{
		if ($key === null && $rawMessage === null && $message === null && $table === null && $column === null) {
			throw InvalidArgument::create()
				->withMessage('Baseline filter must define at least one of key, rawMessage, message, table or column.');
		}

		$this->key = $key;
		$this->rawMessage = $rawMessage;
		$this->message = $message;
		$this->table = $table;
		$this->column = $column;
	}

	/**
	 * @param array{key: string|null, rawMessage: string|null, table: string|null, column: string|null, count: int|null} $entry
	 */
	public function matches(array $entry): bool
	{
		if ($this->key !== null && $entry['key'] !== $this->key) {
			return false;
		}

		if ($this->rawMessage !== null && $entry['rawMessage'] !== $this->rawMessage) {
			return false;
		}

		if ($this->message !== null && preg_match('#' . $this->message . '#', (string) $entry['rawMessage']) !== 1) {
			return false;
		}

		if ($this->table !== null && $entry['table'] !== $this->table) {
			return false;
		}

		return $this->column === null || $entry['column'] === $this->column;
	}

}

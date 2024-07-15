<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Ignore;

use Orisai\Exceptions\Logic\InvalidArgument;

final class BaselineFilter
{

	/** @readonly */
	private ?string $key;

	/** @readonly */
	private ?string $rawMessage;

	/** @readonly */
	private ?string $table;

	/** @readonly */
	private ?string $column;

	/** @readonly */
	private ?int $count;

	public function __construct(
		?string $key = null,
		?string $rawMessage = null,
		?int $count = null,
		?string $table = null,
		?string $column = null
	)
	{
		if ($key === null && $rawMessage === null && $count === null && $table === null && $column === null) {
			throw InvalidArgument::create()
				->withMessage('Baseline filter must define at least one of key, rawMessage, count, table or column.');
		}

		$this->key = $key;
		$this->rawMessage = $rawMessage;
		$this->table = $table;
		$this->column = $column;
		$this->count = $count;
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

		if ($this->count !== null && $entry['count'] !== $this->count) {
			return false;
		}

		if ($this->table !== null && $entry['table'] !== $this->table) {
			return false;
		}

		return $this->column === null || $entry['column'] === $this->column;
	}

}

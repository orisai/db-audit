<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Ignore;

use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\TableViolationSource;
use Orisai\DbAudit\Report\Violation;
use Orisai\Exceptions\Logic\InvalidArgument;
use function preg_match;

final class IgnoredError
{

	/** @readonly */
	private ?string $rawMessage;

	/** @readonly */
	private ?string $message;

	/** @readonly */
	private ?string $table;

	/** @readonly */
	private ?string $column;

	/** @readonly */
	private ?string $key;

	/** @readonly */
	private ?int $count;

	public function __construct(
		?string $rawMessage = null,
		?string $message = null,
		?string $table = null,
		?string $column = null,
		?string $key = null,
		?int $count = null
	)
	{
		if ($rawMessage !== null && $message !== null) {
			throw InvalidArgument::create()
				->withMessage('Ignored error cannot define both rawMessage and message.');
		}

		if (
			$rawMessage === null
			&& $message === null
			&& $table === null
			&& $column === null
			&& $key === null
		) {
			throw InvalidArgument::create()
				->withMessage(
					'Ignored error must define at least one of rawMessage, message, table, column or key.',
				);
		}

		if ($count !== null && $count < 1) {
			throw InvalidArgument::create()
				->withMessage('Ignored error count must be at least 1.');
		}

		$this->rawMessage = $rawMessage;
		$this->message = $message;
		$this->table = $table;
		$this->column = $column;
		$this->key = $key;
		$this->count = $count;
	}

	public function matchesCriteria(Violation $violation): bool
	{
		if ($this->rawMessage !== null && $violation->getMessage() !== $this->rawMessage) {
			return false;
		}

		if ($this->message !== null && preg_match($this->message, $violation->getMessage()) !== 1) {
			return false;
		}

		if ($this->key !== null && $violation->getKey() !== $this->key) {
			return false;
		}

		if ($this->table !== null && $this->sourceTable($violation) !== $this->table) {
			return false;
		}

		return $this->column === null || $this->sourceColumn($violation) === $this->column;
	}

	private function sourceTable(Violation $violation): ?string
	{
		$source = $violation->getSource();

		if ($source instanceof ColumnViolationSource || $source instanceof TableViolationSource) {
			return $source->getTable();
		}

		return null;
	}

	private function sourceColumn(Violation $violation): ?string
	{
		$source = $violation->getSource();

		return $source instanceof ColumnViolationSource ? $source->getColumn() : null;
	}

	public function getRawMessage(): ?string
	{
		return $this->rawMessage;
	}

	public function getMessage(): ?string
	{
		return $this->message;
	}

	public function getTable(): ?string
	{
		return $this->table;
	}

	public function getColumn(): ?string
	{
		return $this->column;
	}

	public function getKey(): ?string
	{
		return $this->key;
	}

	public function getCount(): ?int
	{
		return $this->count;
	}

}

<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Report;

use Orisai\DbAudit\Change\ChangeRequest;

final class Violation
{

	/** @var literal-string */
	private string $key;

	private string $message;

	private ViolationSource $source;

	private bool $fixable;

	private ?string $hint;

	/** @var list<ChangeRequest> */
	private array $changes;

	/**
	 * @param literal-string      $key
	 * @param list<ChangeRequest> $changes
	 */
	public function __construct(
		string $key,
		string $message,
		ViolationSource $source,
		bool $fixable = false,
		?string $hint = null,
		array $changes = []
	)
	{
		$this->key = $key;
		$this->message = $message;
		$this->source = $source;
		$this->fixable = $fixable;
		$this->hint = $hint;
		$this->changes = $changes;
	}

	/**
	 * @return literal-string
	 */
	public function getKey(): string
	{
		return $this->key;
	}

	public function getMessage(): string
	{
		return $this->message;
	}

	public function getSource(): ViolationSource
	{
		return $this->source;
	}

	public function isFixable(): bool
	{
		return $this->fixable;
	}

	public function getHint(): ?string
	{
		return $this->hint;
	}

	/**
	 * @return list<ChangeRequest>
	 */
	public function getChanges(): array
	{
		return $this->changes;
	}

}

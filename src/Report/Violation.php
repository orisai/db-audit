<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Report;

final class Violation
{

	/** @var literal-string */
	private string $key;

	private string $message;

	private ViolationSource $source;

	/**
	 * @param literal-string $key
	 */
	public function __construct(string $key, string $message, ViolationSource $source)
	{
		$this->key = $key;
		$this->message = $message;
		$this->source = $source;
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

}

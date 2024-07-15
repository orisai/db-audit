<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Report;

final class Advisory
{

	/** @readonly */
	private string $message;

	/** @readonly */
	private ?ViolationSource $source;

	public function __construct(string $message, ?ViolationSource $source = null)
	{
		$this->message = $message;
		$this->source = $source;
	}

	public function getMessage(): string
	{
		return $this->message;
	}

	public function getSource(): ?ViolationSource
	{
		return $this->source;
	}

}

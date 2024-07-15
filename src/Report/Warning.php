<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Report;

final class Warning
{

	/** @readonly */
	private string $message;

	/** @readonly */
	private ?string $analyser;

	public function __construct(string $message, ?string $analyser = null)
	{
		$this->message = $message;
		$this->analyser = $analyser;
	}

	public function getMessage(): string
	{
		return $this->message;
	}

	public function getAnalyser(): ?string
	{
		return $this->analyser;
	}

}

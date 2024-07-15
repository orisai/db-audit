<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Collation;

use ValueError;

final class LegacyCharsetConversion
{

	private const Report = 'report',
		Genuine = 'genuine',
		DoubleEncoded = 'double-encoded';

	private const ValuesAndNames = [
		self::Report => 'Report',
		self::Genuine => 'Genuine',
		self::DoubleEncoded => 'DoubleEncoded',
	];

	/** @readonly */
	public string $name;

	/** @readonly */
	public string $value;

	/** @var array<string, self> */
	private static array $instances = [];

	private function __construct(string $name, string $value)
	{
		$this->name = $name;
		$this->value = $value;
	}

	public static function report(): self
	{
		return self::from(self::Report);
	}

	public static function assumeGenuine(): self
	{
		return self::from(self::Genuine);
	}

	public static function assumeDoubleEncoded(): self
	{
		return self::from(self::DoubleEncoded);
	}

	public static function tryFrom(string $value): ?self
	{
		$key = self::ValuesAndNames[$value] ?? null;

		if ($key === null) {
			return null;
		}

		return self::$instances[$key] ??= new self($key, $value);
	}

	public static function from(string $value): self
	{
		$self = self::tryFrom($value);

		if ($self === null) {
			throw new ValueError();
		}

		return $self;
	}

	/**
	 * @return array<self>
	 */
	public static function cases(): array
	{
		$cases = [];
		foreach (self::ValuesAndNames as $value => $name) {
			$cases[] = self::from($value);
		}

		return $cases;
	}

}

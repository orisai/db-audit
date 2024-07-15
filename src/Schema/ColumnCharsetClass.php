<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Schema;

use ValueError;

final class ColumnCharsetClass
{

	private const Any = 'any',
		NonUtf8mb4 = 'non_utf8mb4',
		SingleByte = 'single_byte';

	private const ValuesAndNames = [
		self::Any => 'Any',
		self::NonUtf8mb4 => 'NonUtf8mb4',
		self::SingleByte => 'SingleByte',
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

	public static function any(): self
	{
		return self::from(self::Any);
	}

	public static function nonUtf8mb4(): self
	{
		return self::from(self::NonUtf8mb4);
	}

	public static function singleByte(): self
	{
		return self::from(self::SingleByte);
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

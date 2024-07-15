<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Change;

use ValueError;

final class AlterLock
{

	private const Default = 'default',
		None = 'none',
		Shared = 'shared',
		Exclusive = 'exclusive';

	private const ValuesAndNames = [
		self::Default => 'Default',
		self::None => 'None',
		self::Shared => 'Shared',
		self::Exclusive => 'Exclusive',
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

	public static function default(): self
	{
		return self::from(self::Default);
	}

	public static function none(): self
	{
		return self::from(self::None);
	}

	public static function shared(): self
	{
		return self::from(self::Shared);
	}

	public static function exclusive(): self
	{
		return self::from(self::Exclusive);
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

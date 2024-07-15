<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Driver;

use ValueError;

final class DatabaseEngine
{

	private const Mysql = 'mysql',
		Mariadb = 'mariadb';

	private const ValuesAndNames = [
		self::Mysql => 'Mysql',
		self::Mariadb => 'Mariadb',
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

	public static function mysql(): self
	{
		return self::from(self::Mysql);
	}

	public static function mariadb(): self
	{
		return self::from(self::Mariadb);
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

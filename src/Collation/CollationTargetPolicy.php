<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Collation;

use ValueError;

final class CollationTargetPolicy
{

	private const PreserveOrder = 'preserve_order',
		Modernize = 'modernize';

	private const ValuesAndNames = [
		self::PreserveOrder => 'PreserveOrder',
		self::Modernize => 'Modernize',
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

	public static function preserveOrder(): self
	{
		return self::from(self::PreserveOrder);
	}

	public static function modernize(): self
	{
		return self::from(self::Modernize);
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

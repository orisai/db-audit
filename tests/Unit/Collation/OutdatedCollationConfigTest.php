<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Unit\Collation;

use Orisai\DbAudit\Collation\CollationTargetPolicy;
use Orisai\DbAudit\Collation\DatabaseDefaultHandling;
use Orisai\DbAudit\Collation\LegacyCharsetConversion;
use Orisai\DbAudit\Collation\OutdatedCollationConfig;
use Orisai\DbAudit\Collation\TableNameFilter;
use PHPUnit\Framework\TestCase;

final class OutdatedCollationConfigTest extends TestCase
{

	public function testDefaults(): void
	{
		$config = new OutdatedCollationConfig();

		self::assertSame(CollationTargetPolicy::preserveOrder(), $config->getTargetPolicy());
		self::assertTrue($config->convertsUtf8mb3());
		self::assertSame(LegacyCharsetConversion::report(), $config->getLegacyCharsetConversion());
		self::assertSame(DatabaseDefaultHandling::auto(), $config->getDatabaseDefault());
		self::assertNull($config->getExecutionAccount());
		self::assertFalse($config->forcesUniqueIndexConversion());
		self::assertFalse($config->getExcludeTables()->matches('anything'));
	}

	public function testSetters(): void
	{
		$config = (new OutdatedCollationConfig())
			->setTargetPolicy(CollationTargetPolicy::modernize())
			->setConvertUtf8mb3(false)
			->setLegacyCharsetConversion(LegacyCharsetConversion::assumeDoubleEncoded())
			->setForceUniqueIndexConversion(true)
			->setExecutionAccount('app@%')
			->setExcludeTables((new TableNameFilter())->withGlob('_*'));

		self::assertSame(CollationTargetPolicy::modernize(), $config->getTargetPolicy());
		self::assertFalse($config->convertsUtf8mb3());
		self::assertSame(
			LegacyCharsetConversion::assumeDoubleEncoded(),
			$config->getLegacyCharsetConversion(),
		);
		self::assertTrue($config->forcesUniqueIndexConversion());
		self::assertSame('app@%', $config->getExecutionAccount());
		self::assertTrue($config->getExcludeTables()->matches('_cache'));
	}

}

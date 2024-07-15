<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Dbal;

use DateTime;
use DateTimeImmutable;
use Dibi\Connection as DibiConnection;
use Nextras\Dbal\Connection as NextrasConnection;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Dbal\DibiAdapter;
use Orisai\DbAudit\Dbal\NextrasAdapter;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\MysqlConnectionConfig;

final class DbalAdapterTest extends TestCase
{

	private function getConfig(): MysqlConnectionConfig
	{
		return new MysqlConnectionConfig(
			'127.0.0.1',
			'root',
			'root',
			(int) '3306',
		);
	}

	public function testDibi(): void
	{
		$dbal = new DibiAdapter(new DibiConnection($this->getConfig()->toDibi()));
		$this->testInternal($dbal);
	}

	public function testNextras(): void
	{
		$dbal = new NextrasAdapter(new NextrasConnection($this->getConfig()->toNextras()));
		$this->testInternal($dbal);
	}

	/**
	 * Workaround for code coverage not working in providers
	 */
	private function testInternal(DbalAdapter $dbal): void
	{
		$query = $dbal->query('SELECT version() as version;');

		self::assertCount(1, $query);
		self::assertStringMatchesFormat('%d.%d.%d', $query[0]['version']);

		$exec = $dbal->exec('SELECT * FROM INFORMATION_SCHEMA.COLUMNS LIMIT 10;');
		self::assertSame(10, $exec);

		self::assertSame('1', $dbal->escapeBool(true));
		self::assertSame('0', $dbal->escapeBool(false));

		self::assertContains(
			$dbal->escapeDateTime((new DateTimeImmutable())->setTimestamp(0)),
			[
				"'1970-01-01 00:00:00'",
				"'1970-01-01 00:00:00.000000'",
			],
		);
		self::assertContains(
			$dbal->escapeDateTime((new DateTime())->setTimestamp(0)),
			[
				"'1970-01-01 00:00:00'",
				"'1970-01-01 00:00:00.000000'",
			],
		);

		self::assertSame('1', $dbal->escapeInt(1));
		self::assertSame('10', $dbal->escapeInt(10));

		self::assertSame("''", $dbal->escapeString(''));
		self::assertSame("'any'", $dbal->escapeString('any'));
		self::assertSame("'true'", $dbal->escapeString('true'));
		self::assertSame("'select'", $dbal->escapeString('select'));

		self::assertSame('``', $dbal->escapeIdentifier(''));
		self::assertSame('`any`', $dbal->escapeIdentifier('any'));
		self::assertSame('`true`', $dbal->escapeIdentifier('true'));
		self::assertSame('`select`', $dbal->escapeIdentifier('select'));
	}

}

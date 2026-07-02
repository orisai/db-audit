<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Auditor;

use Generator;
use Orisai\DbAudit\Analyser;
use Orisai\DbAudit\Auditor\AutoIncrementNearLimitMysqlAuditor;
use Orisai\DbAudit\Auditor\BoolLikeColumnMysqlAuditor;
use Orisai\DbAudit\Auditor\EmptyColumnMysqlAuditor;
use Orisai\DbAudit\Auditor\EmptyTableMysqlAuditor;
use Orisai\DbAudit\Auditor\ForeignKeyColumnNameMismatchMysqlAuditor;
use Orisai\DbAudit\Auditor\ForeignKeyColumnTypeMismatchMysqlAuditor;
use Orisai\DbAudit\Auditor\ForeignKeyReferencedColumnExistenceMysqlAuditor;
use Orisai\DbAudit\Auditor\ForeignKeyViolationMysqlAuditor;
use Orisai\DbAudit\Auditor\InvalidDateMysqlAuditor;
use Orisai\DbAudit\Auditor\InvalidDefaultDateMysqlAuditor;
use Orisai\DbAudit\Auditor\Latin1EncodingMysqlAuditor;
use Orisai\DbAudit\Auditor\MissingPrimaryKeyMysqlAuditor;
use Orisai\DbAudit\Auditor\MixedEmptyValuesMysqlAuditor;
use Orisai\DbAudit\Auditor\NonTransactionalEngineMysqlAuditor;
use Orisai\DbAudit\Auditor\NullableWithNoNullsMysqlAuditor;
use Orisai\DbAudit\Auditor\OutdatedCollationMysqlAuditor;
use Orisai\DbAudit\Auditor\RedundantIndexMysqlAuditor;
use Orisai\DbAudit\Auditor\UniqueIndexCollationCollisionMysqlAuditor;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Schema\SchemaProvider;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\DbProvider;
use function get_class;
use function sprintf;

final class AuditorSupportedDatabasesTest extends TestCase
{

	protected function tearDown(): void
	{
		parent::tearDown();
		DbProvider::disconnectAll();
	}

	/**
	 * @return Generator<string, array{0: DbalAdapter, 1: DatabaseEngine}>
	 */
	public function provide(): Generator
	{
		yield from DbProvider::adapters();
	}

	/**
	 * Every auditor must declare its supported databases and run on the MySQL and MariaDB
	 * servers the suite targets; skipIfUnsupported() is the same guard a runtime runner would use.
	 *
	 * @dataProvider provide
	 */
	public function test(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		foreach (self::auditors($dbal) as $auditor) {
			DbProvider::skipIfUnsupported($auditor, $dbal);

			self::assertNotSame(
				[],
				$auditor->getSupportedDatabases(),
				sprintf('%s on %s declares no supported databases', get_class($auditor), $engine->value),
			);
		}
	}

	/**
	 * @return list<Analyser>
	 */
	private static function auditors(DbalAdapter $dbal): array
	{
		$schema = new SchemaProvider($dbal);

		return [
			new AutoIncrementNearLimitMysqlAuditor($schema),
			new BoolLikeColumnMysqlAuditor($schema),
			new EmptyColumnMysqlAuditor($schema),
			new EmptyTableMysqlAuditor($schema),
			new ForeignKeyColumnNameMismatchMysqlAuditor($schema),
			new ForeignKeyColumnTypeMismatchMysqlAuditor($schema),
			new ForeignKeyReferencedColumnExistenceMysqlAuditor($schema),
			new ForeignKeyViolationMysqlAuditor($schema),
			new InvalidDateMysqlAuditor($schema),
			new InvalidDefaultDateMysqlAuditor($schema),
			new Latin1EncodingMysqlAuditor($schema),
			new MissingPrimaryKeyMysqlAuditor($schema),
			new MixedEmptyValuesMysqlAuditor($schema),
			new NonTransactionalEngineMysqlAuditor($schema),
			new NullableWithNoNullsMysqlAuditor($schema),
			new OutdatedCollationMysqlAuditor($schema),
			new RedundantIndexMysqlAuditor($schema),
			new UniqueIndexCollationCollisionMysqlAuditor($schema),
		];
	}

}

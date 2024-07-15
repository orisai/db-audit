<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Auditor;

use Generator;
use Orisai\DbAudit\Auditor\ForeignKeyReferencedColumnExistenceMysqlAuditor;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\DbProvider;
use Tests\Orisai\DbAudit\Helper\MysqlShortcuts;
use Throwable;

final class ForeignKeyReferencedColumnExistenceMysqlAuditorTest extends TestCase
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
	 * @dataProvider provide
	 */
	public function testNonExistentTable(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$auditor = new ForeignKeyReferencedColumnExistenceMysqlAuditor($dbal);

		$key = 'foreign_key.referenced_table_missing';

		$db = 'foreign_key_referenced_column_existence__non_existent_table';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		self::assertEquals([], $auditor->analyse()->getViolations());

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
SET FOREIGN_KEY_CHECKS = 0;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE references_nonexistent_table_2 (
	id INT NOT NULL AUTO_INCREMENT,
	ref_id INT,
	PRIMARY KEY (id),
	FOREIGN KEY (ref_id) REFERENCES nonexistent_table(id)
) ENGINE=InnoDB;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE references_nonexistent_table (
	id INT NOT NULL AUTO_INCREMENT,
	ref_id_2 INT,
	ref_id INT,
	PRIMARY KEY (id),
	FOREIGN KEY (ref_id_2) REFERENCES nonexistent_table(id),
	FOREIGN KEY (ref_id) REFERENCES nonexistent_table(id)
) ENGINE=InnoDB;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
SET FOREIGN_KEY_CHECKS = 1;
SQL,
		);

		$report = $auditor->analyse()->getViolations();
		self::assertEquals([
			new Violation(
				$key,
				'Foreign key of column [references_nonexistent_table][ref_id]'
				. ' references column [nonexistent_table][id] but the referenced table does not exist.',
				new ColumnViolationSource($db, null, 'references_nonexistent_table', 'ref_id'),
			),
			new Violation(
				$key,
				'Foreign key of column [references_nonexistent_table][ref_id_2]'
				. ' references column [nonexistent_table][id] but the referenced table does not exist.',
				new ColumnViolationSource($db, null, 'references_nonexistent_table', 'ref_id_2'),
			),
			new Violation(
				$key,
				'Foreign key of column [references_nonexistent_table_2][ref_id]'
				. ' references column [nonexistent_table][id] but the referenced table does not exist.',
				new ColumnViolationSource($db, null, 'references_nonexistent_table_2', 'ref_id'),
			),
		], $auditor->analyse()->getViolations());
		self::assertEquals($report, $auditor->analyse()->getViolations());
	}

	/**
	 * This test only checks that non-existent column is impossible
	 *
	 * @dataProvider provide
	 */
	public function testNonExistentColumn(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);

		$db = 'foreign_key_referenced_column_existence__non_existent_column';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
SET FOREIGN_KEY_CHECKS = 0;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `referenced_table` (
	`id` INT NOT NULL AUTO_INCREMENT,
	PRIMARY KEY (`id`)
) ENGINE=InnoDB;
SQL,
		);

		$exception = null;
		try {
			$dbal->exec(
			/** @lang MySQL */
				<<<'SQL'
CREATE TABLE references_nonexistent_column (
	id INT NOT NULL AUTO_INCREMENT,
	ref_id INT,
	PRIMARY KEY (id),
	FOREIGN KEY (ref_id) REFERENCES referenced_table(non_existent_column)
) ENGINE=InnoDB;
SQL,
			);
		} catch (Throwable $exception) {
			// Handled bellow
		}

		// Cannot refer to non-existent column of an existing table
		self::assertNotNull($exception);
		if ($engine === DatabaseEngine::mariadb()) {
			// MariaDB reports the malformed foreign key generically instead of naming the missing column.
			self::assertStringContainsString(
				'Foreign key constraint is incorrectly formed',
				$exception->getMessage(),
			);
		} else {
			self::assertStringStartsWith(
				'Failed to add the foreign key constraint. Missing column ',
				$exception->getMessage(),
			);
		}
	}

}

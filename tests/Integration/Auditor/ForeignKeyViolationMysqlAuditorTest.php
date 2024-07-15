<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Auditor;

use Dibi\Connection as DibiConnection;
use Generator;
use Nextras\Dbal\Connection as NextrasConnection;
use Orisai\DbAudit\Auditor\ForeignKeyViolationMysqlAuditor;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Dbal\DibiAdapter;
use Orisai\DbAudit\Dbal\NextrasAdapter;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\MysqlConnectionConfig;
use Tests\Orisai\DbAudit\Helper\MysqlShortcuts;
use Throwable;

final class ForeignKeyViolationMysqlAuditorTest extends TestCase
{

	public function provide(): Generator
	{
		$config = new MysqlConnectionConfig(
			'127.0.0.1',
			'root',
			'root',
			(int) '3306',
		);

		yield [
			new DibiAdapter(new DibiConnection($config->toDibi())),
		];

		yield [
			new NextrasAdapter(new NextrasConnection($config->toNextras())),
		];
	}

	/**
	 * @dataProvider provide
	 */
	public function testKey(DbalAdapter $dbal): void
	{
		$auditor = new ForeignKeyViolationMysqlAuditor($dbal);

		$key = $auditor::getKey();
		self::assertSame('foreign_key_violation', $key);
	}

	/**
	 * @dataProvider provide
	 */
	public function testMismatch(DbalAdapter $dbal): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$auditor = new ForeignKeyViolationMysqlAuditor($dbal);

		$key = $auditor::getKey();

		$db = 'foreign_key_violation__mismatch';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		self::assertEquals([], $auditor->analyse());

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

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `valid_references` (
	`id` INT NOT NULL AUTO_INCREMENT,
	`ref_a` INT,
	`ref_b` INT,
	PRIMARY KEY (`id`),
	FOREIGN KEY (`ref_a`) REFERENCES `referenced_table`(`id`),
	FOREIGN KEY (`ref_b`) REFERENCES `referenced_table`(`id`)
) ENGINE=InnoDB;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `mixed_references_b` (
	`id` INT NOT NULL AUTO_INCREMENT,
	`ref_a` INT,
	`ref_b` INT,
	PRIMARY KEY (`id`),
	FOREIGN KEY (`ref_a`) REFERENCES `referenced_table`(`id`),
	FOREIGN KEY (`ref_b`) REFERENCES `referenced_table`(`id`)
) ENGINE=InnoDB;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `mixed_references` (
	`id` INT NOT NULL AUTO_INCREMENT,
	`ref_b` INT,
	`ref_a` INT,
	PRIMARY KEY (`id`),
	FOREIGN KEY (`ref_a`) REFERENCES `referenced_table`(`id`),
	FOREIGN KEY (`ref_b`) REFERENCES `referenced_table`(`id`)
) ENGINE=InnoDB;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `referenced_table` (`id`)
VALUES (1), (2), (3);
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `valid_references` (`ref_a`, `ref_b`)
VALUES (1, 2), (2, 3), (null, null)
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `mixed_references_b` (`ref_a`, `ref_b`)
VALUES (1, 2), (99, 3), (2, 99), (99, 99);
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `mixed_references` (`ref_a`, `ref_b`)
VALUES (1, 99), (99, 2);
SQL,
		);

		$report = $auditor->analyse();
		self::assertEquals([
			new Violation(
				$key,
				'Foreign key of column [mixed_references][ref_a] references column [referenced_table][id]'
				. ' but some of the referenced records do not exist.',
				new ColumnViolationSource($db, null, 'mixed_references', 'ref_a'),
			),
			new Violation(
				$key,
				'Foreign key of column [mixed_references][ref_b] references column [referenced_table][id]'
				. ' but some of the referenced records do not exist.',
				new ColumnViolationSource($db, null, 'mixed_references', 'ref_b'),
			),
			new Violation(
				$key,
				'Foreign key of column [mixed_references_b][ref_a] references column [referenced_table][id]'
				. ' but some of the referenced records do not exist.',
				new ColumnViolationSource($db, null, 'mixed_references_b', 'ref_a'),
			),
			new Violation(
				$key,
				'Foreign key of column [mixed_references_b][ref_b] references column [referenced_table][id]'
				. ' but some of the referenced records do not exist.',
				new ColumnViolationSource($db, null, 'mixed_references_b', 'ref_b'),
			),
		], $report);
		self::assertEquals($report, $auditor->analyse());
	}

	/**
	 * This test only checks that the procedure doesn't fail
	 *
	 * @dataProvider provide
	 */
	public function testNonExistentTable(DbalAdapter $dbal): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$auditor = new ForeignKeyViolationMysqlAuditor($dbal);

		$db = 'foreign_key_violation__non_existent_table';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		self::assertEquals([], $auditor->analyse());

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
SET FOREIGN_KEY_CHECKS = 0;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE references_nonexistent_table (
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
SET FOREIGN_KEY_CHECKS = 1;
SQL,
		);

		$report = $auditor->analyse();
		self::assertEquals([], $auditor->analyse());
		self::assertEquals($report, $auditor->analyse());
	}

	/**
	 * This test only checks that non-existent column is impossible
	 *
	 * @dataProvider provide
	 */
	public function testNonExistentColumn(DbalAdapter $dbal): void
	{
		$shortcuts = new MysqlShortcuts($dbal);

		$db = 'foreign_key_violation__non_existent_column';
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
		self::assertStringStartsWith(
			'Failed to add the foreign key constraint. Missing column ',
			$exception->getMessage(),
		);
	}

}

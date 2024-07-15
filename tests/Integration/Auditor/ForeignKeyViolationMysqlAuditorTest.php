<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Auditor;

use Generator;
use Orisai\DbAudit\Auditor\ForeignKeyViolationMysqlAuditor;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\DbProvider;
use Tests\Orisai\DbAudit\Helper\MysqlShortcuts;
use Throwable;

final class ForeignKeyViolationMysqlAuditorTest extends TestCase
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
	public function testMismatch(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$auditor = new ForeignKeyViolationMysqlAuditor($dbal);

		$key = 'foreign_key.violation';

		$db = 'foreign_key_violation__mismatch';
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

		$report = $auditor->analyse()->getViolations();
		self::assertEquals([
			new Violation(
				$key,
				'Foreign key of column [mixed_references][ref_a] references column [referenced_table][id]'
				. ' but some of the referenced records do not exist.',
				new ColumnViolationSource($db, null, 'mixed_references', 'ref_a'),
				false,
				'Delete or fix the orphan rows before relying on the constraint.',
			),
			new Violation(
				$key,
				'Foreign key of column [mixed_references][ref_b] references column [referenced_table][id]'
				. ' but some of the referenced records do not exist.',
				new ColumnViolationSource($db, null, 'mixed_references', 'ref_b'),
				false,
				'Delete or fix the orphan rows before relying on the constraint.',
			),
			new Violation(
				$key,
				'Foreign key of column [mixed_references_b][ref_a] references column [referenced_table][id]'
				. ' but some of the referenced records do not exist.',
				new ColumnViolationSource($db, null, 'mixed_references_b', 'ref_a'),
				false,
				'Delete or fix the orphan rows before relying on the constraint.',
			),
			new Violation(
				$key,
				'Foreign key of column [mixed_references_b][ref_b] references column [referenced_table][id]'
				. ' but some of the referenced records do not exist.',
				new ColumnViolationSource($db, null, 'mixed_references_b', 'ref_b'),
				false,
				'Delete or fix the orphan rows before relying on the constraint.',
			),
		], $report);
		self::assertEquals($report, $auditor->analyse()->getViolations());
	}

	/**
	 * A composite foreign key is violated only when no parent row matches the full referenced tuple, so a
	 * per-column check (the previous implementation) misjudges it. A referencing row with a NULL in any column
	 * is not checked (MySQL MATCH SIMPLE).
	 *
	 * @dataProvider provide
	 */
	public function testCompositeForeignKeyViolation(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$auditor = new ForeignKeyViolationMysqlAuditor($dbal);

		$key = 'foreign_key.violation';

		$db = 'foreign_key_violation__composite';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `p` (
	`a` INT NOT NULL,
	`b` INT NOT NULL,
	PRIMARY KEY (`a`, `b`)
) ENGINE=InnoDB;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `c` (
	`id` INT NOT NULL AUTO_INCREMENT,
	`fa` INT,
	`fb` INT,
	PRIMARY KEY (`id`),
	KEY `fk` (`fa`, `fb`),
	CONSTRAINT `fk` FOREIGN KEY (`fa`, `fb`) REFERENCES `p`(`a`, `b`)
) ENGINE=InnoDB;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `p` (`a`, `b`)
VALUES (1, 1), (2, 2);
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
SET FOREIGN_KEY_CHECKS = 0;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `c` (`fa`, `fb`)
VALUES (1, 1), (1, 2), (null, 5);
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
				'Foreign key of column [c][fa] references column [p][a]'
				. ' but some of the referenced records do not exist.',
				new ColumnViolationSource($db, null, 'c', 'fa'),
				false,
				'Delete or fix the orphan rows before relying on the constraint.',
			),
		], $report);
		self::assertEquals($report, $auditor->analyse()->getViolations());
	}

	/**
	 * This test only checks that the procedure doesn't fail
	 *
	 * @dataProvider provide
	 */
	public function testNonExistentTable(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$auditor = new ForeignKeyViolationMysqlAuditor($dbal);

		$db = 'foreign_key_violation__non_existent_table';
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

		$report = $auditor->analyse()->getViolations();
		self::assertEquals([], $auditor->analyse()->getViolations());
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

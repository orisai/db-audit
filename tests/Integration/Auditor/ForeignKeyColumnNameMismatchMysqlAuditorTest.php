<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Auditor;

use Generator;
use Orisai\DbAudit\Auditor\ForeignKeyColumnNameMismatchConfig;
use Orisai\DbAudit\Auditor\ForeignKeyColumnNameMismatchMysqlAuditor;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;
use Orisai\DbAudit\Schema\SchemaProvider;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\AuditorRunner;
use Tests\Orisai\DbAudit\Helper\DbProvider;
use Tests\Orisai\DbAudit\Helper\MysqlShortcuts;

final class ForeignKeyColumnNameMismatchMysqlAuditorTest extends TestCase
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
	public function testEmptyDatabase(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$schema = new SchemaProvider($dbal);
		$auditor = new ForeignKeyColumnNameMismatchMysqlAuditor($schema);

		$db = 'foreign_key_column_name_mismatch__empty';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		self::assertEquals([], AuditorRunner::analyse($schema, $auditor)->getViolations());
	}

	/**
	 * @dataProvider provide
	 */
	public function testDefaultPattern(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$schema = new SchemaProvider($dbal);
		$auditor = new ForeignKeyColumnNameMismatchMysqlAuditor($schema);

		$db = 'foreign_key_column_name_mismatch__default';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `target` (
	`id` INT NOT NULL AUTO_INCREMENT,
	PRIMARY KEY (`id`)
) ENGINE=InnoDB;
SQL,
		);

		// thing_id is the primary key but not auto_increment: isolates the primary-key exclusion.
		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `pk_only` (
	`thing_id` INT NOT NULL,
	PRIMARY KEY (`thing_id`)
) ENGINE=InnoDB;
SQL,
		);

		// x_id is auto_increment but NOT the primary key: isolates the auto_increment exclusion.
		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `auto_inc` (
	`x_id` INT NOT NULL AUTO_INCREMENT,
	`other` INT NOT NULL,
	PRIMARY KEY (`other`),
	UNIQUE KEY `uq_x_id` (`x_id`)
) ENGINE=InnoDB;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `t` (
	`id` INT NOT NULL AUTO_INCREMENT,
	`parent_id` INT NULL,
	`constrained_id` INT NULL,
	`parent` INT NULL,
	PRIMARY KEY (`id`),
	CONSTRAINT `fk_constrained_id` FOREIGN KEY (`constrained_id`) REFERENCES `target` (`id`),
	CONSTRAINT `fk_parent` FOREIGN KEY (`parent`) REFERENCES `target` (`id`)
) ENGINE=InnoDB;
SQL,
		);

		$report = AuditorRunner::analyse($schema, $auditor)->getViolations();
		self::assertEquals([
			new Violation(
				'foreign_key.unexpected_name',
				'Column [t][parent] has a foreign key but does not match the foreign-key naming pattern.',
				new ColumnViolationSource($db, null, 't', 'parent'),
			),
			new Violation(
				'foreign_key.missing_constraint',
				'Column [t][parent_id] matches the foreign-key naming pattern but has no foreign key.',
				new ColumnViolationSource($db, null, 't', 'parent_id'),
			),
		], $report);

		// Determinism: re-running yields the same result.
		self::assertEquals($report, AuditorRunner::analyse($schema, $auditor)->getViolations());
	}

	/**
	 * @dataProvider provide
	 */
	public function testCustomPattern(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$config = (new ForeignKeyColumnNameMismatchConfig())->setPattern('/^fk_/');
		$schema = new SchemaProvider($dbal);
		$auditor = new ForeignKeyColumnNameMismatchMysqlAuditor($schema, $config);
		$key = 'foreign_key.missing_constraint';

		$db = 'foreign_key_column_name_mismatch__custom';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `c` (
	`id` INT NOT NULL AUTO_INCREMENT,
	`fk_parent` INT NULL,
	`parent_id` INT NULL,
	PRIMARY KEY (`id`)
) ENGINE=InnoDB;
SQL,
		);

		self::assertEquals([
			new Violation(
				$key,
				'Column [c][fk_parent] matches the foreign-key naming pattern but has no foreign key.',
				new ColumnViolationSource($db, null, 'c', 'fk_parent'),
			),
		], AuditorRunner::analyse($schema, $auditor)->getViolations());
	}

	/**
	 * @dataProvider provide
	 */
	public function testReanalyseReflectsChange(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$schema = new SchemaProvider($dbal);
		$auditor = new ForeignKeyColumnNameMismatchMysqlAuditor($schema);
		$key = 'foreign_key.missing_constraint';

		$db = 'foreign_key_column_name_mismatch__reanalyse';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `first` (
	`id` INT NOT NULL AUTO_INCREMENT,
	`parent_id` INT NULL,
	PRIMARY KEY (`id`)
) ENGINE=InnoDB;
SQL,
		);

		self::assertEquals([
			new Violation(
				$key,
				'Column [first][parent_id] matches the foreign-key naming pattern but has no foreign key.',
				new ColumnViolationSource($db, null, 'first', 'parent_id'),
			),
		], AuditorRunner::analyse($schema, $auditor)->getViolations());

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `second` (
	`id` INT NOT NULL AUTO_INCREMENT,
	`owner_id` INT NULL,
	PRIMARY KEY (`id`)
) ENGINE=InnoDB;
SQL,
		);

		self::assertEquals([
			new Violation(
				$key,
				'Column [first][parent_id] matches the foreign-key naming pattern but has no foreign key.',
				new ColumnViolationSource($db, null, 'first', 'parent_id'),
			),
			new Violation(
				$key,
				'Column [second][owner_id] matches the foreign-key naming pattern but has no foreign key.',
				new ColumnViolationSource($db, null, 'second', 'owner_id'),
			),
		], AuditorRunner::analyse($schema, $auditor)->getViolations());
	}

}

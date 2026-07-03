<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Auditor;

use Generator;
use Orisai\DbAudit\Auditor\ForeignKeyColumnTypeMismatchMysqlAuditor;
use Orisai\DbAudit\Auditor\ForeignKeyReferencedColumnExistenceMysqlAuditor;
use Orisai\DbAudit\Change\ColumnTargetChange;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;
use Orisai\DbAudit\Schema\SchemaProvider;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\AuditorRunner;
use Tests\Orisai\DbAudit\Helper\CountingDbalAdapter;
use Tests\Orisai\DbAudit\Helper\DbProvider;
use Tests\Orisai\DbAudit\Helper\MysqlShortcuts;
use Throwable;

final class ForeignKeyColumnTypeMismatchMysqlAuditorTest extends TestCase
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
	public function testCharacterSet(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$schema = new SchemaProvider($dbal);
		$auditor = new ForeignKeyColumnTypeMismatchMysqlAuditor($schema);

		$key = 'foreign_key.charset_mismatch';

		$db = 'foreign_key_column_type_mismatch__character_set';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		self::assertEquals([], AuditorRunner::analyse($schema, $auditor)->getViolations());

		if ($engine === DatabaseEngine::mariadb()) {
			// MariaDB enforces a matching character set when a foreign key is created and refuses to alter,
			// convert, or re-add a foreign-key column to a different character set (every path raises "Cannot
			// change column ... used in a foreign key constraint" or "incompatible"). A genuine
			// charset-mismatched-but-existing foreign key therefore cannot be constructed on MariaDB, so the
			// detection scenario is exercised on MySQL only; here a matching-charset foreign key must produce
			// no charset violation (the auditor must not false-positive).
			$dbal->exec(
			/** @lang MySQL */
				<<<'SQL'
CREATE TABLE referenced_table (
	id VARCHAR(10) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
	PRIMARY KEY (id)
) ENGINE=InnoDB;
SQL,
			);

			$dbal->exec(
			/** @lang MySQL */
				<<<'SQL'
CREATE TABLE varchar_charset_foreign_key (
	ref_id VARCHAR(10) CHARACTER SET utf8 COLLATE utf8_general_ci,
	FOREIGN KEY (ref_id) REFERENCES referenced_table(id)
) ENGINE=InnoDB;
SQL,
			);

			self::assertEquals([], AuditorRunner::analyse($schema, $auditor)->getViolations());

			return;
		}

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
SET FOREIGN_KEY_CHECKS = 0;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE referenced_table (
	id VARCHAR(10) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
	PRIMARY KEY (id)
) ENGINE=InnoDB;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE varchar_charset_foreign_key_2 (
	ref_id VARCHAR(10) CHARACTER SET utf8 COLLATE utf8_general_ci,
	FOREIGN KEY (ref_id) REFERENCES referenced_table(id)
) ENGINE=InnoDB;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE varchar_charset_foreign_key (
	ref_id_2 VARCHAR(10) CHARACTER SET utf8 COLLATE utf8_general_ci,
	ref_id VARCHAR(10) CHARACTER SET utf8 COLLATE utf8_general_ci,
	FOREIGN KEY (ref_id_2) REFERENCES referenced_table(id),
	FOREIGN KEY (ref_id) REFERENCES referenced_table(id)
) ENGINE=InnoDB;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
ALTER TABLE varchar_charset_foreign_key_2
	MODIFY COLUMN ref_id VARCHAR(10) CHARACTER SET latin1 COLLATE latin1_german1_ci;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
ALTER TABLE varchar_charset_foreign_key
	MODIFY COLUMN ref_id_2 VARCHAR(10) CHARACTER SET latin1 COLLATE latin1_spanish_ci,
	MODIFY COLUMN ref_id VARCHAR(10) CHARACTER SET latin1 COLLATE latin1_swedish_ci;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
SET FOREIGN_KEY_CHECKS = 1;
SQL,
		);

		// The parent id is CHARACTER SET utf8 (MAXLEN 3), reported as utf8mb3 by MySQL 8; each child was narrowed
		// to latin1 (MAXLEN 1). latin1 is a single-byte legacy charset, so conversion is blocked — every charset
		// mismatch stays report-only regardless of MAXLEN ordering.
		$report = AuditorRunner::analyse($schema, $auditor)->getViolations();
		self::assertEquals([
			new Violation(
				$key,
				'Column [varchar_charset_foreign_key][ref_id] references column [referenced_table][id]'
				. ' but the character set does not match.',
				new ColumnViolationSource($db, null, 'varchar_charset_foreign_key', 'ref_id'),
			),
			new Violation(
				$key,
				'Column [varchar_charset_foreign_key][ref_id_2] references column [referenced_table][id]'
				. ' but the character set does not match.',
				new ColumnViolationSource($db, null, 'varchar_charset_foreign_key', 'ref_id_2'),
			),
			new Violation(
				$key,
				'Column [varchar_charset_foreign_key_2][ref_id] references column [referenced_table][id]'
				. ' but the character set does not match.',
				new ColumnViolationSource($db, null, 'varchar_charset_foreign_key_2', 'ref_id'),
			),
		], AuditorRunner::analyse($schema, $auditor)->getViolations());
		self::assertEquals($report, AuditorRunner::analyse($schema, $auditor)->getViolations());
	}

	/**
	 * @dataProvider provide
	 */
	public function testSize(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$schema = new SchemaProvider($dbal);
		$auditor = new ForeignKeyColumnTypeMismatchMysqlAuditor($schema);

		$key = 'foreign_key.size_mismatch';

		$db = 'foreign_key_column_type_mismatch__size';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		self::assertEquals([], AuditorRunner::analyse($schema, $auditor)->getViolations());

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
SET FOREIGN_KEY_CHECKS = 0;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE referenced_table (
	id VARCHAR(20) NOT NULL,
	PRIMARY KEY (id)
) ENGINE=InnoDB;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE varchar_size_foreign_key_2 (
	ref_id VARCHAR(10),
	FOREIGN KEY (ref_id) REFERENCES referenced_table(id)
) ENGINE=InnoDB;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE varchar_size_foreign_key (
	ref_id_2 VARCHAR(10),
	ref_id VARCHAR(10),
	FOREIGN KEY (ref_id_2) REFERENCES referenced_table(id),
	FOREIGN KEY (ref_id) REFERENCES referenced_table(id)
) ENGINE=InnoDB;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
SET FOREIGN_KEY_CHECKS = 1;
SQL,
		);

		// Both endpoints share the database default charset (utf8mb4), so there is no charset mismatch; each child
		// is varchar(10) and the parent varchar(20). Same base type, child shorter, so every size mismatch is a
		// WIDEN: fixable by widening the child to the parent length.
		$hint = 'Run db-audit:analyse --category=structure --generate-fix=migration.sql to produce the migration SQL.';
		$report = AuditorRunner::analyse($schema, $auditor)->getViolations();
		self::assertEquals([
			new Violation(
				$key,
				'Column [varchar_size_foreign_key][ref_id] references column [referenced_table][id]'
				. ' but the column size does not match.',
				new ColumnViolationSource($db, null, 'varchar_size_foreign_key', 'ref_id'),
				true,
				$hint,
				[
					ColumnTargetChange::forColumn($db, 'varchar_size_foreign_key', 'ref_id')
						->setType('varchar(20)')
						->setCharLength(20),
				],
			),
			new Violation(
				$key,
				'Column [varchar_size_foreign_key][ref_id_2] references column [referenced_table][id]'
				. ' but the column size does not match.',
				new ColumnViolationSource($db, null, 'varchar_size_foreign_key', 'ref_id_2'),
				true,
				$hint,
				[
					ColumnTargetChange::forColumn($db, 'varchar_size_foreign_key', 'ref_id_2')
						->setType('varchar(20)')
						->setCharLength(20),
				],
			),
			new Violation(
				$key,
				'Column [varchar_size_foreign_key_2][ref_id] references column [referenced_table][id]'
				. ' but the column size does not match.',
				new ColumnViolationSource($db, null, 'varchar_size_foreign_key_2', 'ref_id'),
				true,
				$hint,
				[
					ColumnTargetChange::forColumn($db, 'varchar_size_foreign_key_2', 'ref_id')
						->setType('varchar(20)')
						->setCharLength(20),
				],
			),
		], AuditorRunner::analyse($schema, $auditor)->getViolations());
		self::assertEquals($report, AuditorRunner::analyse($schema, $auditor)->getViolations());
	}

	/**
	 * A char/varchar size mismatch where the child is shorter than the parent is a WIDEN: fixable by widening
	 * the child to the parent length. Built with FOREIGN_KEY_CHECKS off, so it runs on both engines.
	 *
	 * @dataProvider provide
	 */
	public function testSizeWiden(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$schema = new SchemaProvider($dbal);
		$auditor = new ForeignKeyColumnTypeMismatchMysqlAuditor($schema);

		$db = 'foreign_key_column_type_mismatch__size_widen';
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
CREATE TABLE referenced_table (
	id VARCHAR(30) NOT NULL,
	PRIMARY KEY (id)
) ENGINE=InnoDB;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE referencing_table (
	ref_id VARCHAR(20),
	FOREIGN KEY (ref_id) REFERENCES referenced_table(id)
) ENGINE=InnoDB;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
SET FOREIGN_KEY_CHECKS = 1;
SQL,
		);

		$hint = 'Run db-audit:analyse --category=structure --generate-fix=migration.sql to produce the migration SQL.';
		self::assertEquals([
			new Violation(
				'foreign_key.size_mismatch',
				'Column [referencing_table][ref_id] references column [referenced_table][id]'
				. ' but the column size does not match.',
				new ColumnViolationSource($db, null, 'referencing_table', 'ref_id'),
				true,
				$hint,
				[
					ColumnTargetChange::forColumn($db, 'referencing_table', 'ref_id')
						->setType('varchar(30)')
						->setCharLength(30),
				],
			),
		], AuditorRunner::analyse($schema, $auditor)->getViolations());
	}

	/**
	 * A charset mismatch where the child is latin1 (MAXLEN 1) and the parent utf8mb4 (MAXLEN 4): despite the
	 * child being strictly narrower, latin1 is a single-byte legacy charset, so the conversion is blocked and
	 * the mismatch stays report-only. Constructing it needs an ALTER of a foreign-key column's charset, which
	 * only MySQL allows.
	 *
	 * @dataProvider provide
	 */
	public function testCharsetSafeAlign(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$schema = new SchemaProvider($dbal);
		$auditor = new ForeignKeyColumnTypeMismatchMysqlAuditor($schema);

		$db = 'foreign_key_column_type_mismatch__charset_safe';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		if ($engine === DatabaseEngine::mariadb()) {
			// MariaDB refuses to alter a foreign-key column's charset, so a charset-mismatched-but-existing
			// foreign key cannot be constructed; a matching-charset foreign key must raise no violation.
			$dbal->exec(
			/** @lang MySQL */
				<<<'SQL'
CREATE TABLE referenced_table (
	id VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
	PRIMARY KEY (id)
) ENGINE=InnoDB;
SQL,
			);

			$dbal->exec(
			/** @lang MySQL */
				<<<'SQL'
CREATE TABLE referencing_table (
	ref_id VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
	FOREIGN KEY (ref_id) REFERENCES referenced_table(id)
) ENGINE=InnoDB;
SQL,
			);

			self::assertEquals([], AuditorRunner::analyse($schema, $auditor)->getViolations());

			return;
		}

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
SET FOREIGN_KEY_CHECKS = 0;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE referenced_table (
	id VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
	PRIMARY KEY (id)
) ENGINE=InnoDB;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE referencing_table (
	ref_id VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
	FOREIGN KEY (ref_id) REFERENCES referenced_table(id)
) ENGINE=InnoDB;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
ALTER TABLE referencing_table
	MODIFY COLUMN ref_id VARCHAR(10) CHARACTER SET latin1 COLLATE latin1_swedish_ci;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
SET FOREIGN_KEY_CHECKS = 1;
SQL,
		);

		self::assertEquals([
			new Violation(
				'foreign_key.charset_mismatch',
				'Column [referencing_table][ref_id] references column [referenced_table][id]'
				. ' but the character set does not match.',
				new ColumnViolationSource($db, null, 'referencing_table', 'ref_id'),
			),
		], AuditorRunner::analyse($schema, $auditor)->getViolations());
	}

	/**
	 * A charset mismatch where the child is utf8mb3 (MAXLEN 3) and the parent utf8mb4 (MAXLEN 4) is SAFE-ALIGN:
	 * utf8mb3 is not a single-byte legacy charset, so the child adopts the parent's charset and collation.
	 * Constructing the mismatch needs an ALTER of a foreign-key column's charset, which only MySQL allows.
	 *
	 * @dataProvider provide
	 */
	public function testCharsetSafeAlignUtf8mb3(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$schema = new SchemaProvider($dbal);
		$auditor = new ForeignKeyColumnTypeMismatchMysqlAuditor($schema);

		$db = 'foreign_key_column_type_mismatch__charset_safe_utf8mb3';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		if ($engine === DatabaseEngine::mariadb()) {
			// MariaDB refuses to alter a foreign-key column's charset, so a charset-mismatched-but-existing
			// foreign key cannot be constructed; a matching-charset foreign key must raise no violation.
			$dbal->exec(
			/** @lang MySQL */
				<<<'SQL'
CREATE TABLE referenced_table (
	id VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
	PRIMARY KEY (id)
) ENGINE=InnoDB;
SQL,
			);

			$dbal->exec(
			/** @lang MySQL */
				<<<'SQL'
CREATE TABLE referencing_table (
	ref_id VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
	FOREIGN KEY (ref_id) REFERENCES referenced_table(id)
) ENGINE=InnoDB;
SQL,
			);

			self::assertEquals([], AuditorRunner::analyse($schema, $auditor)->getViolations());

			return;
		}

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
SET FOREIGN_KEY_CHECKS = 0;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE referenced_table (
	id VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
	PRIMARY KEY (id)
) ENGINE=InnoDB;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE referencing_table (
	ref_id VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
	FOREIGN KEY (ref_id) REFERENCES referenced_table(id)
) ENGINE=InnoDB;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
ALTER TABLE referencing_table
	MODIFY COLUMN ref_id VARCHAR(10) CHARACTER SET utf8 COLLATE utf8_general_ci;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
SET FOREIGN_KEY_CHECKS = 1;
SQL,
		);

		// utf8mb3 (MAXLEN 3) -> utf8mb4 (MAXLEN 4): child is strictly narrower and not single-byte legacy,
		// so the charset mismatch is SAFE-ALIGN: fixable by aligning the child charset/collation to the parent's.
		$hint = 'Run db-audit:analyse --category=structure --generate-fix=migration.sql to produce the migration SQL.';
		self::assertEquals([
			new Violation(
				'foreign_key.charset_mismatch',
				'Column [referencing_table][ref_id] references column [referenced_table][id]'
				. ' but the character set does not match.',
				new ColumnViolationSource($db, null, 'referencing_table', 'ref_id'),
				true,
				$hint,
				[
					ColumnTargetChange::forColumn($db, 'referencing_table', 'ref_id')
						->setCharsetCollation('utf8mb4', 'utf8mb4_general_ci'),
				],
			),
		], AuditorRunner::analyse($schema, $auditor)->getViolations());
	}

	/**
	 * A charset mismatch that would narrow the child (utf8mb4 -> latin1) is UNSAFE: it poisons the whole pair,
	 * so even a co-occurring widen-able size mismatch stays report-only (a partial fix would fail the FK re-add).
	 * Constructing it needs an ALTER of a foreign-key column's charset, which only MySQL allows.
	 *
	 * @dataProvider provide
	 */
	public function testCharsetUnsafe(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$schema = new SchemaProvider($dbal);
		$auditor = new ForeignKeyColumnTypeMismatchMysqlAuditor($schema);

		$db = 'foreign_key_column_type_mismatch__charset_unsafe';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		if ($engine === DatabaseEngine::mariadb()) {
			// MariaDB refuses to alter a foreign-key column's charset, so the unsafe mismatch cannot be built;
			// a matching-charset foreign key must raise no violation.
			$dbal->exec(
			/** @lang MySQL */
				<<<'SQL'
CREATE TABLE referenced_table (
	id VARCHAR(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
	PRIMARY KEY (id)
) ENGINE=InnoDB;
SQL,
			);

			$dbal->exec(
			/** @lang MySQL */
				<<<'SQL'
CREATE TABLE referencing_table (
	ref_id VARCHAR(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci,
	FOREIGN KEY (ref_id) REFERENCES referenced_table(id)
) ENGINE=InnoDB;
SQL,
			);

			self::assertEquals([], AuditorRunner::analyse($schema, $auditor)->getViolations());

			return;
		}

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
SET FOREIGN_KEY_CHECKS = 0;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE referenced_table (
	id VARCHAR(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
	PRIMARY KEY (id)
) ENGINE=InnoDB;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE referencing_table (
	ref_id VARCHAR(10) CHARACTER SET latin1 COLLATE latin1_swedish_ci,
	FOREIGN KEY (ref_id) REFERENCES referenced_table(id)
) ENGINE=InnoDB;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
ALTER TABLE referencing_table
	MODIFY COLUMN ref_id VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
SET FOREIGN_KEY_CHECKS = 1;
SQL,
		);

		// The child is utf8mb4 varchar(10), the parent latin1 varchar(20): the charset would narrow (UNSAFE) and
		// the size would widen. Because the pair cannot be made fully valid, BOTH mismatches stay report-only.
		self::assertEquals([
			new Violation(
				'foreign_key.charset_mismatch',
				'Column [referencing_table][ref_id] references column [referenced_table][id]'
				. ' but the character set does not match.',
				new ColumnViolationSource($db, null, 'referencing_table', 'ref_id'),
			),
			new Violation(
				'foreign_key.size_mismatch',
				'Column [referencing_table][ref_id] references column [referenced_table][id]'
				. ' but the column size does not match.',
				new ColumnViolationSource($db, null, 'referencing_table', 'ref_id'),
			),
		], AuditorRunner::analyse($schema, $auditor)->getViolations());
	}

	/**
	 * A base-type mismatch (char vs varchar) is INCOMPATIBLE: widening cannot reconcile it, so the size mismatch
	 * stays report-only. Built with FOREIGN_KEY_CHECKS off, so it runs on both engines.
	 *
	 * @dataProvider provide
	 */
	public function testCharVsVarchar(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$schema = new SchemaProvider($dbal);
		$auditor = new ForeignKeyColumnTypeMismatchMysqlAuditor($schema);

		$db = 'foreign_key_column_type_mismatch__char_vs_varchar';
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
CREATE TABLE referenced_table (
	id VARCHAR(10) NOT NULL,
	PRIMARY KEY (id)
) ENGINE=InnoDB;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE referencing_table (
	ref_id CHAR(10),
	FOREIGN KEY (ref_id) REFERENCES referenced_table(id)
) ENGINE=InnoDB;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
SET FOREIGN_KEY_CHECKS = 1;
SQL,
		);

		self::assertEquals([
			new Violation(
				'foreign_key.size_mismatch',
				'Column [referencing_table][ref_id] references column [referenced_table][id]'
				. ' but the column size does not match.',
				new ColumnViolationSource($db, null, 'referencing_table', 'ref_id'),
			),
		], AuditorRunner::analyse($schema, $auditor)->getViolations());
	}

	/**
	 * A char/varchar size mismatch where the child is already at least as wide as the parent is BENIGN: it is a
	 * valid foreign key, so the size mismatch stays report-only (never shrink the child). Built with
	 * FOREIGN_KEY_CHECKS off, so it runs on both engines.
	 *
	 * @dataProvider provide
	 */
	public function testSizeBenign(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$schema = new SchemaProvider($dbal);
		$auditor = new ForeignKeyColumnTypeMismatchMysqlAuditor($schema);

		$db = 'foreign_key_column_type_mismatch__size_benign';
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
CREATE TABLE referenced_table (
	id VARCHAR(30) NOT NULL,
	PRIMARY KEY (id)
) ENGINE=InnoDB;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE referencing_table (
	ref_id VARCHAR(40),
	FOREIGN KEY (ref_id) REFERENCES referenced_table(id)
) ENGINE=InnoDB;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
SET FOREIGN_KEY_CHECKS = 1;
SQL,
		);

		self::assertEquals([
			new Violation(
				'foreign_key.size_mismatch',
				'Column [referencing_table][ref_id] references column [referenced_table][id]'
				. ' but the column size does not match.',
				new ColumnViolationSource($db, null, 'referencing_table', 'ref_id'),
			),
		], AuditorRunner::analyse($schema, $auditor)->getViolations());
	}

	/**
	 * This test only checks that the procedure doesn't fail
	 *
	 * @dataProvider provide
	 */
	public function testNonExistentTable(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$schema = new SchemaProvider($dbal);
		$auditor = new ForeignKeyColumnTypeMismatchMysqlAuditor($schema);

		$db = 'foreign_key_column_type_mismatch__non_existent_table';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		self::assertEquals([], AuditorRunner::analyse($schema, $auditor)->getViolations());

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

		$report = AuditorRunner::analyse($schema, $auditor)->getViolations();
		self::assertEquals([], AuditorRunner::analyse($schema, $auditor)->getViolations());
		self::assertEquals($report, AuditorRunner::analyse($schema, $auditor)->getViolations());
	}

	/**
	 * This test only checks that non-existent column is impossible
	 *
	 * @dataProvider provide
	 */
	public function testNonExistentColumn(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);

		$db = 'foreign_key_column_type_mismatch__non_existent_column';
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

	/**
	 * @dataProvider provide
	 */
	public function testType(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$schema = new SchemaProvider($dbal);
		$auditor = new ForeignKeyColumnTypeMismatchMysqlAuditor($schema);

		$db = 'foreign_key_column_type_mismatch__type';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		self::assertEquals([], AuditorRunner::analyse($schema, $auditor)->getViolations());

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
SET FOREIGN_KEY_CHECKS = 0;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE referenced_table (
	id INT NOT NULL AUTO_INCREMENT,
	PRIMARY KEY (id)
) ENGINE=InnoDB;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE referencing_table (
	id INT NOT NULL AUTO_INCREMENT,
	ref_id INT,
	PRIMARY KEY (id),
	FOREIGN KEY (ref_id) REFERENCES referenced_table(id)
) ENGINE=InnoDB;
SQL,
		);

		$exception = null;
		try {
			$dbal->exec(
			/** @lang MySQL */
				<<<'SQL'
ALTER TABLE referencing_table MODIFY COLUMN ref_id BIGINT;
SQL,
			);
		} catch (Throwable $exception) {
			// Handled bellow
		}

		self::assertNotNull($exception);
		if ($engine === DatabaseEngine::mariadb()) {
			// MariaDB refuses to alter a column that participates in a foreign key rather than reporting the
			// type incompatibility; the constraint name suffix carries a database prefix in some states.
			self::assertStringStartsWith(
				"Cannot change column 'ref_id': used in a foreign key constraint",
				$exception->getMessage(),
			);
		} else {
			self::assertSame(
				"Referencing column 'ref_id' and referenced column 'id' in foreign key constraint 'referencing_table_ibfk_1' are incompatible.",
				$exception->getMessage(),
			);
		}
	}

	/**
	 * @dataProvider provide
	 */
	public function testSign(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);

		$db = 'foreign_key_column_type_mismatch__sign';
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
CREATE TABLE referenced_table (
	id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	PRIMARY KEY (id)
) ENGINE=InnoDB;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE referencing_table (
	ref_id INT UNSIGNED,
	FOREIGN KEY (ref_id) REFERENCES referenced_table(id)
) ENGINE=InnoDB;
SQL,
		);

		$exception = null;
		try {
			$dbal->exec(
			/** @lang MySQL */
				<<<'SQL'
ALTER TABLE referencing_table MODIFY COLUMN ref_id INT;
SQL,
			);
		} catch (Throwable $exception) {
			// Handled bellow
		}

		self::assertNotNull($exception);
		if ($engine === DatabaseEngine::mariadb()) {
			self::assertStringStartsWith(
				"Cannot change column 'ref_id': used in a foreign key constraint",
				$exception->getMessage(),
			);
		} else {
			self::assertSame(
				"Referencing column 'ref_id' and referenced column 'id' in foreign key constraint 'referencing_table_ibfk_1' are incompatible.",
				$exception->getMessage(),
			);
		}
	}

	/**
	 * @dataProvider provide
	 */
	public function testCollation(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);

		$db = 'foreign_key_column_type_mismatch__collation';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE referenced_table (
	id VARCHAR(10) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
	PRIMARY KEY (id)
) ENGINE=InnoDB;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE referencing_table (
	ref_id VARCHAR(10) CHARACTER SET utf8 COLLATE utf8_general_ci,
	FOREIGN KEY (ref_id) REFERENCES referenced_table(id)
) ENGINE=InnoDB;
SQL,
		);

		$exception = null;
		try {
			$dbal->exec(
			/** @lang MySQL */
				<<<'SQL'
ALTER TABLE referencing_table MODIFY COLUMN ref_id VARCHAR(10) CHARACTER SET utf8 COLLATE utf8_unicode_ci;
SQL,
			);
		} catch (Throwable $exception) {
			// Handled bellow
		}

		self::assertNotNull($exception);
		if ($engine === DatabaseEngine::mariadb()) {
			self::assertStringStartsWith(
				"Cannot change column 'ref_id': used in a foreign key constraint",
				$exception->getMessage(),
			);
		} else {
			self::assertSame(
				"Referencing column 'ref_id' and referenced column 'id' in foreign key constraint 'referencing_table_ibfk_1' are incompatible.",
				$exception->getMessage(),
			);
		}
	}

	/**
	 * @dataProvider provide
	 */
	public function testOnUpdate(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);

		$db = 'foreign_key_column_type_mismatch__on_update';
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
CREATE TABLE referenced_table (
	id INT NOT NULL AUTO_INCREMENT,
	PRIMARY KEY (id)
) ENGINE=InnoDB;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE referencing_table (
	ref_id INT NOT NULL,
	FOREIGN KEY (ref_id) REFERENCES referenced_table(id)
) ENGINE=InnoDB;
SQL,
		);

		$exception = null;
		try {
			$dbal->exec(
			/** @lang MySQL */
				<<<'SQL'
ALTER TABLE referencing_table
ADD CONSTRAINT
	FOREIGN KEY (ref_id)
	REFERENCES referenced_table(id)
	ON UPDATE SET NULL;
SQL,
			);
		} catch (Throwable $exception) {
			// Handled bellow
		}

		self::assertNotNull($exception);
		if ($engine === DatabaseEngine::mariadb()) {
			// MariaDB rejects a SET NULL referential action on a NOT NULL column as malformed FK options
			// rather than naming the offending column.
			self::assertSame(
				"Failed to add the foreign key constraint on table 'referencing_table'."
				. " Incorrect options in FOREIGN KEY constraint '(null)'",
				$exception->getMessage(),
			);
		} else {
			self::assertSame(
				"Column 'ref_id' cannot be NOT NULL: needed in a foreign key constraint 'referencing_table_ibfk_2' SET NULL",
				$exception->getMessage(),
			);
		}
	}

	/**
	 * @dataProvider provide
	 */
	public function testOnDelete(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);

		$db = 'foreign_key_column_type_mismatch__on_delete';
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
CREATE TABLE referenced_table (
	id INT NOT NULL AUTO_INCREMENT,
	PRIMARY KEY (id)
) ENGINE=InnoDB;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE referencing_table (
	ref_id INT NOT NULL,
	FOREIGN KEY (ref_id) REFERENCES referenced_table(id)
) ENGINE=InnoDB;
SQL,
		);

		$exception = null;
		try {
			$dbal->exec(
			/** @lang MySQL */
				<<<'SQL'
ALTER TABLE referencing_table
ADD CONSTRAINT
	FOREIGN KEY (ref_id)
	REFERENCES referenced_table(id)
	ON DELETE SET NULL;
SQL,
			);
		} catch (Throwable $exception) {
			// Handled bellow
		}

		self::assertNotNull($exception);
		if ($engine === DatabaseEngine::mariadb()) {
			// MariaDB rejects a SET NULL referential action on a NOT NULL column as malformed FK options
			// rather than naming the offending column.
			self::assertSame(
				"Failed to add the foreign key constraint on table 'referencing_table'."
				. " Incorrect options in FOREIGN KEY constraint '(null)'",
				$exception->getMessage(),
			);
		} else {
			self::assertSame(
				"Column 'ref_id' cannot be NOT NULL: needed in a foreign key constraint 'referencing_table_ibfk_2' SET NULL",
				$exception->getMessage(),
			);
		}
	}

	/**
	 * Both foreign-key auditors built on one shared SchemaProvider must read the foreign-key snapshot
	 * (INFORMATION_SCHEMA.KEY_COLUMN_USAGE) at most once between them.
	 *
	 * @dataProvider provide
	 */
	public function testSharedSchemaProvider(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);

		$db = 'foreign_key_column_type_mismatch__shared';
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
CREATE TABLE referenced_table (
	id VARCHAR(10) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
	PRIMARY KEY (id)
) ENGINE=InnoDB;
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE referencing_table (
	ref_id VARCHAR(10) CHARACTER SET utf8 COLLATE utf8_general_ci,
	FOREIGN KEY (ref_id) REFERENCES referenced_table(id)
) ENGINE=InnoDB;
SQL,
		);

		if ($engine === DatabaseEngine::mysql()) {
			// Introducing a real charset mismatch is MySQL-only (MariaDB refuses to alter a foreign-key
			// column's charset); the shared-schema query-count assertion below holds regardless of whether the
			// type-mismatch auditor finds a violation, so the foreign key stays matching on MariaDB.
			$dbal->exec(
			/** @lang MySQL */
				<<<'SQL'
ALTER TABLE referencing_table
	MODIFY COLUMN ref_id VARCHAR(10) CHARACTER SET latin1 COLLATE latin1_german1_ci;
SQL,
			);
		}

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE references_nonexistent_table (
	ref_id INT,
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

		$counting = new CountingDbalAdapter($dbal);
		$schema = new SchemaProvider($counting);

		$typeMismatch = new ForeignKeyColumnTypeMismatchMysqlAuditor($schema);
		$referencedExistence = new ForeignKeyReferencedColumnExistenceMysqlAuditor($schema);

		$typeMismatch->analyse();
		$referencedExistence->analyse();

		self::assertLessThanOrEqual(1, $counting->getQueryCountContaining('KEY_COLUMN_USAGE'));
	}

}

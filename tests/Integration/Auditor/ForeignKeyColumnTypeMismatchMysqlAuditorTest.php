<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Auditor;

use Dibi\Connection as DibiConnection;
use Generator;
use Nextras\Dbal\Connection as NextrasConnection;
use Orisai\DbAudit\Auditor\ForeignKeyColumnTypeMismatchMysqlAuditor;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Dbal\DibiAdapter;
use Orisai\DbAudit\Dbal\NextrasAdapter;
use Orisai\DbAudit\Report\ColumnViolationSource;
use Orisai\DbAudit\Report\Violation;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\MysqlConnectionConfig;
use Tests\Orisai\DbAudit\Helper\MysqlShortcuts;
use Throwable;

final class ForeignKeyColumnTypeMismatchMysqlAuditorTest extends TestCase
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
		$auditor = new ForeignKeyColumnTypeMismatchMysqlAuditor($dbal);

		$key = $auditor::getKey();
		self::assertSame('foreign_key_column_type_mismatch', $key);
	}

	/**
	 * @dataProvider provide
	 */
	public function testCharacterSet(DbalAdapter $dbal): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$auditor = new ForeignKeyColumnTypeMismatchMysqlAuditor($dbal);

		$key = $auditor::getKey();

		$db = 'foreign_key_column_type_mismatch__character_set';
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

		$report = $auditor->analyse();
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
		], $auditor->analyse());
		self::assertEquals($report, $auditor->analyse());
	}

	/**
	 * @dataProvider provide
	 */
	public function testSize(DbalAdapter $dbal): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$auditor = new ForeignKeyColumnTypeMismatchMysqlAuditor($dbal);

		$key = $auditor::getKey();

		$db = 'foreign_key_column_type_mismatch__size';
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

		$report = $auditor->analyse();
		self::assertEquals([
			new Violation(
				$key,
				'Column [varchar_size_foreign_key][ref_id] references column [referenced_table][id]'
				. ' but the column size does not match.',
				new ColumnViolationSource($db, null, 'varchar_size_foreign_key', 'ref_id'),
			),
			new Violation(
				$key,
				'Column [varchar_size_foreign_key][ref_id_2] references column [referenced_table][id]'
				. ' but the column size does not match.',
				new ColumnViolationSource($db, null, 'varchar_size_foreign_key', 'ref_id_2'),
			),
			new Violation(
				$key,
				'Column [varchar_size_foreign_key_2][ref_id] references column [referenced_table][id]'
				. ' but the column size does not match.',
				new ColumnViolationSource($db, null, 'varchar_size_foreign_key_2', 'ref_id'),
			),
		], $auditor->analyse());
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
		$auditor = new ForeignKeyColumnTypeMismatchMysqlAuditor($dbal);

		$db = 'foreign_key_column_type_mismatch__non_existent_table';
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
		self::assertStringStartsWith(
			'Failed to add the foreign key constraint. Missing column ',
			$exception->getMessage(),
		);
	}

	/**
	 * @dataProvider provide
	 */
	public function testType(DbalAdapter $dbal): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$auditor = new ForeignKeyColumnTypeMismatchMysqlAuditor($dbal);

		$db = 'foreign_key_column_type_mismatch__type';
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
		self::assertSame(
			"Referencing column 'ref_id' and referenced column 'id' in foreign key constraint 'referencing_table_ibfk_1' are incompatible.",
			$exception->getMessage(),
		);
	}

	/**
	 * @dataProvider provide
	 */
	public function testSign(DbalAdapter $dbal): void
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
		self::assertSame(
			"Referencing column 'ref_id' and referenced column 'id' in foreign key constraint 'referencing_table_ibfk_1' are incompatible.",
			$exception->getMessage(),
		);
	}

	/**
	 * @dataProvider provide
	 */
	public function testCollation(DbalAdapter $dbal): void
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
		self::assertSame(
			"Referencing column 'ref_id' and referenced column 'id' in foreign key constraint 'referencing_table_ibfk_1' are incompatible.",
			$exception->getMessage(),
		);
	}

	/**
	 * @dataProvider provide
	 */
	public function testOnUpdate(DbalAdapter $dbal): void
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
		self::assertSame(
			"Column 'ref_id' cannot be NOT NULL: needed in a foreign key constraint 'referencing_table_ibfk_2' SET NULL",
			$exception->getMessage(),
		);
	}

	/**
	 * @dataProvider provide
	 */
	public function testOnDelete(DbalAdapter $dbal): void
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
		self::assertSame(
			"Column 'ref_id' cannot be NOT NULL: needed in a foreign key constraint 'referencing_table_ibfk_2' SET NULL",
			$exception->getMessage(),
		);
	}

}

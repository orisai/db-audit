<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Integration\Auditor;

use Generator;
use Orisai\DbAudit\Auditor\Latin1EncodingMysqlAuditor;
use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Driver\DatabaseEngine;
use Orisai\DbAudit\Report\Violation;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\DbAudit\Helper\DbProvider;
use Tests\Orisai\DbAudit\Helper\MysqlShortcuts;
use function array_map;
use function strpos;

final class Latin1EncodingMysqlAuditorTest extends TestCase
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
	public function test(DbalAdapter $dbal, DatabaseEngine $engine): void
	{
		$shortcuts = new MysqlShortcuts($dbal);
		$auditor = new Latin1EncodingMysqlAuditor($dbal);

		$db = 'latin1_encoding';
		$shortcuts->dropDatabaseIfExists($db);
		$shortcuts->createDatabase($db);
		$shortcuts->useDatabase($db);

		// Empty database: nothing to scan.
		self::assertEquals([], $auditor->analyse()->getViolations());

		// One table whose latin1 columns each isolate a verdict, plus utf8mb4/utf8mb3 columns that must be
		// skipped because their charset is multibyte (CHARACTER_SETS.MAXLEN > 1).
		//   0xE9   = byte for 'é' in latin1, INVALID UTF-8 on its own -> genuine single-byte
		//   0xC3A9 = the UTF-8 bytes of 'é' stored verbatim in latin1, VALID UTF-8 -> double-encoded
		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `data` (
	`genuine` varchar(50) CHARACTER SET latin1 NULL,
	`double_encoded` varchar(50) CHARACTER SET latin1 NULL,
	`mixed` varchar(50) CHARACTER SET latin1 NULL,
	`ascii_only` varchar(50) CHARACTER SET latin1 NULL,
	`u4` varchar(50) CHARACTER SET utf8mb4 NULL,
	`u3` varchar(50) CHARACTER SET utf8mb3 NULL
) CHARACTER SET latin1
SQL,
		);

		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `data` (`genuine`, `double_encoded`, `mixed`, `ascii_only`, `u4`, `u3`) VALUES
	(UNHEX('68656C6C6F'), UNHEX('68656C6C6F'), UNHEX('68656C6C6F'), UNHEX('68656C6C6F'), UNHEX('C3A9'), UNHEX('C3A9')),
	(UNHEX('E9'),         UNHEX('C3A9'),       UNHEX('E9'),         UNHEX('776F726C64'), NULL,         NULL),
	(NULL,                NULL,                UNHEX('C3A9'),       NULL,                NULL,         NULL)
SQL,
		);

		$report = $auditor->analyse()->getViolations();

		// Deterministic and repeatable, like the other data auditors.
		self::assertEquals($report, $auditor->analyse()->getViolations());

		// One violation per non-ASCII latin1 column (genuine, double_encoded, mixed) — ordered by column
		// name within the single table. ascii_only has no non-ASCII data, u4/u3 are multibyte (not scanned).
		$messages = array_map(
			static fn (Violation $violation): string => $violation->getMessage(),
			$report,
		);
		self::assertCount(3, $report);

		foreach ($report as $violation) {
			self::assertSame('latin1_encoding', $violation->getKey());
		}

		// double_encoded: only valid-UTF-8 non-ASCII rows.
		self::assertStringContainsString('[data][double_encoded]', $messages[0]);
		self::assertStringContainsString('double-encoded', $messages[0]);
		self::assertStringContainsString('assume-double-encoded', $messages[0]);

		// genuine: only invalid-UTF-8 non-ASCII rows.
		self::assertStringContainsString('[data][genuine]', $messages[1]);
		self::assertStringContainsString('genuine', $messages[1]);
		self::assertStringContainsString('assume-genuine', $messages[1]);

		// mixed: both genuine and double-encoded rows -> manual repair, counts named.
		self::assertStringContainsString('[data][mixed]', $messages[2]);
		self::assertStringContainsString('MIXED', $messages[2]);
		self::assertStringContainsString('manual repair', $messages[2]);
		self::assertStringContainsString('1 row(s) are genuine single-byte', $messages[2]);
		self::assertStringContainsString('1 row(s) are double-encoded UTF-8', $messages[2]);

		// No violation mentions the ASCII-only column or either multibyte column.
		foreach ($messages as $message) {
			self::assertFalse(strpos($message, '[data][ascii_only]') !== false);
			self::assertFalse(strpos($message, '[data][u4]') !== false);
			self::assertFalse(strpos($message, '[data][u3]') !== false);
		}

		// Multibyte charsets are also excluded even on a different single-byte charset table: a latin2 column
		// with a genuine high byte is scanned, while a utf8mb4 sibling holding the same bytes is not.
		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
CREATE TABLE `other` (
	`latin2_col` varchar(50) CHARACTER SET latin2 NULL,
	`utf8_col` varchar(50) CHARACTER SET utf8mb4 NULL
) CHARACTER SET latin2
SQL,
		);
		$dbal->exec(
		/** @lang MySQL */
			<<<'SQL'
INSERT INTO `other` (`latin2_col`, `utf8_col`) VALUES (UNHEX('E9'), UNHEX('C3A9'))
SQL,
		);

		$report2 = $auditor->analyse()->getViolations();
		self::assertEquals($report2, $auditor->analyse()->getViolations());

		$other = [];
		foreach ($report2 as $violation) {
			$message = $violation->getMessage();
			if (strpos($message, '[other]') !== false) {
				$other[] = $message;
			}
		}

		self::assertCount(1, $other);
		self::assertStringContainsString('[other][latin2_col]', $other[0]);
		self::assertStringContainsString('genuine', $other[0]);

		// The session sql_mode the auditor temporarily clears is restored to its prior value afterwards.
		$before = (string) $dbal->query('SELECT @@SESSION.sql_mode AS sql_mode')[0]['sql_mode'];
		$auditor->analyse();
		$after = (string) $dbal->query('SELECT @@SESSION.sql_mode AS sql_mode')[0]['sql_mode'];
		self::assertSame($before, $after);
	}

}

<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Unit\Report;

use Orisai\DbAudit\Change\TableEngineChange;
use Orisai\DbAudit\Report\TableViolationSource;
use Orisai\DbAudit\Report\Violation;
use PHPUnit\Framework\TestCase;

final class ViolationTest extends TestCase
{

	public function test(): void
	{
		$source = new TableViolationSource('database', null, 'table');
		$violation = new Violation('key', 'message', $source);

		self::assertSame('key', $violation->getKey());
		self::assertSame('message', $violation->getMessage());
		self::assertSame($source, $violation->getSource());
		self::assertFalse($violation->isFixable());
		self::assertNull($violation->getHint());
		self::assertSame([], $violation->getChanges());
	}

	public function testFixableWithHintAndChange(): void
	{
		$source = new TableViolationSource('database', null, 'table');
		$change = new TableEngineChange('database', 'table', 'InnoDB');
		$violation = new Violation('key', 'message', $source, true, 'do this', [$change]);

		self::assertTrue($violation->isFixable());
		self::assertSame('do this', $violation->getHint());
		self::assertSame([$change], $violation->getChanges());
	}

}

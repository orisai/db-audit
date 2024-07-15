<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Unit\Report;

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
	}

}

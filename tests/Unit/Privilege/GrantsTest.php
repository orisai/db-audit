<?php declare(strict_types = 1);

namespace Tests\Orisai\DbAudit\Unit\Privilege;

use Orisai\DbAudit\Privilege\Grants;
use PHPUnit\Framework\TestCase;

final class GrantsTest extends TestCase
{

	public function testGlobalAllPrivileges(): void
	{
		$grants = Grants::fromShowGrantsRows([
			['Grants for root@localhost' => 'GRANT ALL PRIVILEGES ON *.* TO `root`@`localhost` WITH GRANT OPTION'],
		]);

		self::assertTrue($grants->has('ALTER'));
		self::assertTrue($grants->has('ALTER', 'spring_local'));
		self::assertTrue($grants->has('SELECT', 'spring_local', 'user'));
	}

	public function testDatabaseScopedPrivileges(): void
	{
		$grants = Grants::fromShowGrantsRows([
			['G' => 'GRANT USAGE ON *.* TO `app`@`%`'],
			['G' => 'GRANT SELECT, INSERT, UPDATE, ALTER ON `spring_local`.* TO `app`@`%`'],
		]);

		self::assertTrue($grants->has('ALTER', 'spring_local'));
		self::assertTrue($grants->has('ALTER', 'spring_local', 'user'));
		self::assertFalse($grants->has('ALTER', 'other_db'));
		self::assertFalse($grants->has('DROP', 'spring_local'));
	}

	public function testTableScopedPrivileges(): void
	{
		$grants = Grants::fromShowGrantsRows([
			['G' => 'GRANT SELECT, ALTER ON `spring_local`.`user` TO `app`@`%`'],
		]);

		self::assertTrue($grants->has('ALTER', 'spring_local', 'user'));
		self::assertFalse($grants->has('ALTER', 'spring_local', 'other_table'));
		self::assertFalse($grants->has('ALTER', 'spring_local')); // db-level not granted
	}

}

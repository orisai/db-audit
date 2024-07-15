<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Privilege;

use Orisai\DbAudit\Dbal\DbalAdapter;

final class GrantsReader
{

	private DbalAdapter $dbal;

	public function __construct(DbalAdapter $dbal)
	{
		$this->dbal = $dbal;
	}

	public function readForCurrentUser(): Grants
	{
		return Grants::fromShowGrantsRows($this->dbal->query('SHOW GRANTS FOR CURRENT_USER()'));
	}

	public function readForAccount(string $user, ?string $host = null): Grants
	{
		$account = $this->dbal->escapeString($user) . '@' . $this->dbal->escapeString($host ?? '%');

		return Grants::fromShowGrantsRows($this->dbal->query('SHOW GRANTS FOR ' . $account));
	}

}

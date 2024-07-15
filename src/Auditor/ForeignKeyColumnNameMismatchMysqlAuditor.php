<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Auditor;

final class ForeignKeyColumnNameMismatchMysqlAuditor extends ForeignKeyColumnNameMismatchAuditor
{

	public function analyse(): array
	{
		// TODO: Implement analyse() method.
		//		- chybějící cizí klíč u sloupce odpovídajícího patternu
		//		- cizí klíč u sloupce neodpovídajícího patternu
		//		- pattern config - regex?
		//			- foo_id
		//			- fkFoo
		return [];
	}

}

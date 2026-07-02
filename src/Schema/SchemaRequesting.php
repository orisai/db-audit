<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Schema;

interface SchemaRequesting
{

	public function getSchemaRequest(): SchemaRequest;

}

<?php

namespace App\Services\Schema\Catalog;

use Illuminate\Database\Connection;

/** A catalog that is one constant statement. */
abstract class SqlCatalog implements Catalog
{
    public function fetch(Connection $db, int $limit): array
    {
        return $db->select($this->sql($limit));
    }
}

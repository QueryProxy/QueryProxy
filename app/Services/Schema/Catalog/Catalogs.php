<?php

namespace App\Services\Schema\Catalog;

use App\Enums\DbDriver;

/** Picks the catalog query of a driver. */
final class Catalogs
{
    public static function for(DbDriver $driver): Catalog
    {
        return match ($driver) {
            DbDriver::Pgsql => new PgsqlCatalog,
            DbDriver::Mysql, DbDriver::Mariadb => new MysqlCatalog,
            DbDriver::Sqlsrv => new SqlsrvCatalog,
            DbDriver::Sqlite => new SqliteCatalog,
        };
    }
}

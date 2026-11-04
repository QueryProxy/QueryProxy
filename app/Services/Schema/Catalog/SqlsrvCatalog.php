<?php

namespace App\Services\Schema\Catalog;

/**
 * SQL Server: the catalog of the database the connection is opened on.
 *
 * The INFORMATION_SCHEMA views and their columns are written in capitals: on a
 * database with a case-sensitive or binary collation a lower-case name is not
 * found. The aliases are ours and stay lower case.
 */
final class SqlsrvCatalog extends SqlCatalog
{
    public function sql(int $limit): string
    {
        $limit = max(1, $limit);

        return <<<SQL
            select top ({$limit})
                c.TABLE_SCHEMA as schema_name,
                c.TABLE_NAME as table_name,
                case when t.TABLE_TYPE = 'VIEW' then 'view' else 'table' end as table_type,
                c.COLUMN_NAME as column_name,
                c.DATA_TYPE as data_type,
                c.IS_NULLABLE as is_nullable
            from INFORMATION_SCHEMA.COLUMNS c
            join INFORMATION_SCHEMA.TABLES t
                on t.TABLE_SCHEMA = c.TABLE_SCHEMA and t.TABLE_NAME = c.TABLE_NAME
            where c.TABLE_SCHEMA not in ('INFORMATION_SCHEMA', 'sys')
            order by case when c.TABLE_SCHEMA = schema_name() then 0 else 1 end, c.TABLE_SCHEMA, c.TABLE_NAME, c.ORDINAL_POSITION
            SQL;
    }

    public function defaultSchemaSql(): ?string
    {
        return 'select schema_name()';
    }
}

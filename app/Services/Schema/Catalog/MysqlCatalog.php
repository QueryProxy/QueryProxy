<?php

namespace App\Services\Schema\Catalog;

/**
 * MySQL and MariaDB: a "schema" is a database. The listing is limited to the
 * connection's own database (user decision, ADR-029): the other databases the
 * login can see are not listed, which also keeps the catalog scan small. A
 * connection with no default database lists nothing.
 */
final class MysqlCatalog extends SqlCatalog
{
    public function sql(int $limit): string
    {
        $limit = max(1, $limit);

        return <<<SQL
            select
                c.table_schema as schema_name,
                c.table_name as table_name,
                case when t.table_type = 'VIEW' then 'view' else 'table' end as table_type,
                c.column_name as column_name,
                c.column_type as data_type,
                c.is_nullable as is_nullable
            from information_schema.columns c
            join information_schema.tables t
                on t.table_schema = c.table_schema and t.table_name = c.table_name
            where c.table_schema = database()
                and c.table_schema not in ('information_schema', 'mysql', 'performance_schema', 'sys')
            order by c.table_name, c.ordinal_position
            limit {$limit}
            SQL;
    }

    public function defaultSchemaSql(): ?string
    {
        return 'select database()';
    }
}

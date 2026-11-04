<?php

namespace App\Services\Schema\Catalog;

/** PostgreSQL and the systems that speak its catalog (CockroachDB, YugabyteDB). */
final class PgsqlCatalog extends SqlCatalog
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
                case when c.data_type in ('USER-DEFINED', 'ARRAY') then c.udt_name else c.data_type end as data_type,
                c.is_nullable as is_nullable
            from information_schema.columns c
            join information_schema.tables t
                on t.table_schema = c.table_schema and t.table_name = c.table_name
            where c.table_schema not in ('information_schema', 'pg_catalog', 'crdb_internal', 'pg_extension')
                and c.table_schema not like 'pg\_%'
            order by case when c.table_schema = current_schema() then 0 else 1 end, c.table_schema, c.table_name, c.ordinal_position
            limit {$limit}
            SQL;
    }

    public function defaultSchemaSql(): ?string
    {
        return 'select current_schema()';
    }
}

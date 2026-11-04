<?php

namespace App\Services\Schema\Catalog;

use Illuminate\Database\Connection;
use Throwable;

/**
 * SQLite: no information_schema. The tables and views come from sqlite_master
 * and the columns of each from the table-valued pragma_table_info, one object
 * at a time: a view whose table was dropped makes its own pragma fail, and that
 * must not take the other objects down with it. Such an object is left out.
 */
final class SqliteCatalog implements Catalog
{
    /** The object list; the columns are read per object in fetch(). */
    public function sql(int $limit): string
    {
        $limit = max(1, $limit);

        return <<<SQL
            select m.name as table_name, m.type as table_type
            from sqlite_master m
            where m.type in ('table', 'view')
                and m.name not like 'sqlite\_%' escape '\'
            order by m.name
            limit {$limit}
            SQL;
    }

    public function fetch(Connection $db, int $limit): array
    {
        $rows = [];

        foreach ($db->select($this->sql($limit)) as $object) {
            try {
                $columns = $db->select(
                    'select p.name as column_name, p.type as data_type, p."notnull" as not_null from pragma_table_info(?) p order by p.cid',
                    [$object->table_name],
                );
            } catch (Throwable) {
                // A broken view: no columns can be listed for it.
                continue;
            }

            foreach ($columns as $column) {
                $rows[] = (object) [
                    'schema_name' => 'main',
                    'table_name' => $object->table_name,
                    'table_type' => $object->table_type,
                    'column_name' => $column->column_name,
                    'data_type' => $column->data_type,
                    'is_nullable' => (int) $column->not_null === 0 ? 'YES' : 'NO',
                ];

                if (count($rows) >= $limit) {
                    return $rows;
                }
            }
        }

        return $rows;
    }

    public function defaultSchemaSql(): ?string
    {
        return null;
    }
}

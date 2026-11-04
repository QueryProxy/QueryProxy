<?php

namespace App\Services\Schema\Catalog;

use Illuminate\Database\Connection;

/**
 * The fixed catalog query of one database driver (ADR-029).
 *
 * The query is a constant of the code: no request value is ever put into it,
 * and user-written SQL never takes this path. It lists every column of every
 * table and view outside the system schemas, one row per column, with these
 * aliases, the connection's default schema first and then by schema, table
 * and column position. The default schema comes first so that a listing cut at
 * the size cap never loses the schema the editor resolves bare names against:
 *
 *   schema_name, table_name, table_type ('table' | 'view'),
 *   column_name, data_type, is_nullable ('YES' | 'NO')
 */
interface Catalog
{
    /**
     * The statement, returning at most $limit rows. $limit is a number the
     * application chose, never request input.
     */
    public function sql(int $limit): string;

    /**
     * Run the catalog on the open read-only session and return its rows, at
     * most $limit of them, in the shape described above.
     *
     * @return list<object|array<string, mixed>>
     */
    public function fetch(Connection $db, int $limit): array;

    /**
     * The schema a name without a schema resolves to, read on the same session;
     * null when the driver has no such notion or it is not asked for.
     */
    public function defaultSchemaSql(): ?string;
}

<?php

namespace App\Services\Schema;

/**
 * Builds the schema > table > column tree from catalog rows (one per column).
 *
 * The rows arrive with the default schema first. When the catalog had more
 * rows than the size cap the listing is cut, and a table cut in the middle is
 * dropped whole: a table with some of its columns would complete wrongly.
 * `truncated` tells that tables are missing.
 */
final class SchemaTree
{
    /**
     * @param  list<object|array<string, mixed>>  $rows  at most $max + 1 of them; the extra one tells the cap was hit
     * @return array{schemas: list<array<string, mixed>>, truncated: bool}
     */
    public static function fromRows(array $rows, int $max): array
    {
        $rows = array_map(fn ($row) => (array) $row, array_values($rows));
        $truncated = count($rows) > $max;

        if ($truncated) {
            $next = $rows[$max];
            $kept = array_slice($rows, 0, $max);
            $last = $kept[$max - 1];

            // The first row left out belongs to the last table kept: that table
            // is incomplete. Drop it, unless it is all there is.
            if (self::sameTable($last, $next)) {
                $complete = array_values(array_filter($kept, fn (array $row) => ! self::sameTable($row, $last)));
                $kept = $complete !== [] ? $complete : $kept;
            }

            $rows = $kept;
        }

        return ['schemas' => self::build($rows), 'truncated' => $truncated];
    }

    /** @param  array<string, mixed>  $a  @param  array<string, mixed>  $b */
    private static function sameTable(array $a, array $b): bool
    {
        return (string) $a['schema_name'] === (string) $b['schema_name'] && (string) $a['table_name'] === (string) $b['table_name'];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function build(array $rows): array
    {
        /** @var array<string, array<string, array{name: string, type: string, columns: list<array<string, mixed>>}>> $schemas */
        $schemas = [];

        foreach ($rows as $row) {
            $schema = (string) $row['schema_name'];
            $table = (string) $row['table_name'];

            $schemas[$schema][$table] ??= [
                'name' => $table,
                'type' => strtolower((string) $row['table_type']) === 'view' ? 'view' : 'table',
                'columns' => [],
            ];

            $schemas[$schema][$table]['columns'][] = [
                'name' => (string) $row['column_name'],
                'type' => (string) $row['data_type'],
                'nullable' => strtoupper((string) $row['is_nullable']) === 'YES',
            ];
        }

        $tree = [];

        foreach ($schemas as $name => $tables) {
            $tree[] = ['name' => (string) $name, 'tables' => array_values($tables)];
        }

        return $tree;
    }
}

<?php

use App\Enums\QueryRequestStatus;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Services\Execution\QueryExecutor;
use Illuminate\Support\Facades\Storage;

/**
 * Mechanism test for the unbuffered fetch (ADR-003 amendment): a large result
 * must pass through the executor without its size showing up in peak memory.
 *
 * The target is SQLite, which steps through a result lazily by nature, so this
 * proves the executor side (row-by-row loop, no collecting, ceiling handling).
 * The MySQL / PostgreSQL side (unbuffered attribute, server-side cursor) is
 * covered by QueryExecutorSessionTest and, against a real server, by the
 * 200k-row CI check (action 030-20).
 */
test('a 50k row, 1 KB per row result streams with a bounded peak memory', function () {
    $rows = 50_000;
    $rowBytes = 1024;

    Storage::fake('local');

    $dbFile = tempnam(sys_get_temp_dir(), 'qp_target_memory_');
    $pdo = new PDO('sqlite:'.$dbFile);
    $pdo->exec('CREATE TABLE blobs (id INTEGER PRIMARY KEY, payload TEXT)');
    $pdo->exec(sprintf(
        'WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < %d) '.
        'INSERT INTO blobs (id, payload) SELECT i, hex(randomblob(%d)) FROM n',
        $rows,
        $rowBytes / 2,
    ));
    unset($pdo);

    config(['queryproxy.select_hard_limit' => $rows]);

    $team = Team::factory()->create();
    $connection = Connection::factory()->sqlite($dbFile)->create(['team_id' => $team->id]);
    $sql = 'SELECT id, payload FROM blobs ORDER BY id';
    $request = QueryRequest::factory()->create([
        'team_id' => $team->id,
        'connection_id' => $connection->id,
        'sql_original' => $sql,
        'sql_prepared' => $sql,
        'status' => QueryRequestStatus::Queued,
        'type' => 'read',
    ]);

    gc_collect_cycles();
    memory_reset_peak_usage();
    $before = memory_get_usage();

    app(QueryExecutor::class)->execute($request);

    $growth = memory_get_peak_usage() - $before;
    // Allocator-level peak (what the OS sees); the ADR metric is the non-real one above.
    $realPeak = memory_get_peak_usage(true);
    $payloadBytes = $rows * $rowBytes;

    fwrite(STDERR, sprintf(
        "\n[memory] %d rows x %d B = %.1f MB of payload, peak growth %.1f MB (real peak %.1f MB)\n",
        $rows,
        $rowBytes,
        $payloadBytes / 1048576,
        $growth / 1048576,
        $realPeak / 1048576,
    ));

    @unlink($dbFile);

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Completed)
        ->and($request->fresh()->result_row_count)->toBe($rows)
        ->and($request->fresh()->result_truncated)->toBeFalse()
        // A tenth of what holding the ~49 MB payload (plus PHP row overhead) would take.
        ->and($growth)->toBeLessThan(8 * 1048576);
});

<?php

/*
 * Unbuffered fetch on a real server (Faz 02, ADR-003): a read request never
 * holds its whole result set in PHP memory. PostgreSQL goes through a
 * server-side cursor, MySQL and MariaDB through an unbuffered PDO result, SQL
 * Server through pdo_sqlsrv's forward-only cursor (the one that had never
 * been checked against a real server).
 *
 * Each test runs the real QueryExecutor in a child process on a table of 1 KB
 * texts and measures its memory two ways: PHP's own memory_get_peak_usage(true)
 * as the process reports it (the figure ADR-003 names), and the anonymous
 * resident memory of the process (Linux /proc RssAnon) sampled from outside
 * while it runs. The second one is what catches PostgreSQL: libpq keeps a
 * plain result in its own malloc'ed memory, which PHP's counter never sees, so
 * only it tells a server-side cursor from a fully buffered read there. The
 * bound is the 64 MB of the acceptance criterion, for PHP's peak and for the
 * growth of the anonymous memory over the process's own start-up.
 *
 * Group "integration"; skipped without QUERYPROXY_IT_* (see IntegrationTarget).
 */

use App\Enums\QueryRequestStatus;
use App\Models\QueryRequest;
use App\Services\Sql\SqlInspector;
use Illuminate\Support\Facades\Storage;
use Tests\Integration\IntegrationTarget;

beforeEach(function () {
    if (! IntegrationTarget::configured()) {
        IntegrationTarget::failIfRequired('the server is not configured.');
        $this->markTestSkipped(IntegrationTarget::SKIP_MESSAGE);
    }

    $this->it = IntegrationTarget::start(nums: true);
});

afterEach(function () {
    if (isset($this->it)) {
        $this->it->stop();
    }
});

/** The acceptance criterion: real peak memory of the process stays under 64 MB. */
function itPeakLimit(): int
{
    return 64 * 1024 * 1024;
}

/**
 * Run the request in a child process and wait for it.
 *
 * @param  array<string, string>  $env
 * @return array{request: QueryRequest, base: int, peak: int, rss_growth: int|null, output: string}
 */
function itRunInChild(IntegrationTarget $it, QueryRequest $request, array $env = []): array
{
    $executor = $it->startExecutor($request, $env);
    $sampled = IntegrationTarget::waitSamplingMemory($executor);

    $output = $executor->getOutput().$executor->getErrorOutput();

    expect($executor->getExitCode())->toBe(0, $output);
    expect($output)->toContain('EXECUTED');

    preg_match('/^MEM base=(\d+) peak=(\d+) rss_anon_base=(\d+)$/m', $output, $memory);
    expect($memory)->not->toBeEmpty($output);

    // Without /proc (a developer's Mac) there is no resident figure; CI has it.
    $growth = $sampled > 0 && (int) $memory[3] > 0 ? $sampled - (int) $memory[3] : null;

    if ($growth === null) {
        IntegrationTarget::failIfRequired('the anonymous resident memory of the child process could not be read (/proc).');
    }

    $mb = fn (int $bytes): string => number_format($bytes / 1048576, 1);
    fwrite(STDERR, sprintf(
        "[memory] %s: PHP base %s MB, peak %s MB; anonymous resident growth %s (limit %s MB)\n",
        IntegrationTarget::driver()->value,
        $mb((int) $memory[1]),
        $mb((int) $memory[2]),
        $growth === null ? 'not measured' : $mb($growth).' MB',
        $mb(itPeakLimit()),
    ));

    return ['request' => $request->fresh(), 'base' => (int) $memory[1], 'peak' => (int) $memory[2], 'rss_growth' => $growth, 'output' => $output];
}

/** The anonymous resident memory of the child grew by less than 64 MB (where it was measured). */
function itAssertResidentGrowth(array $run): void
{
    if ($run['rss_growth'] !== null) {
        expect($run['rss_growth'])->toBeLessThan(itPeakLimit());
    }
}

/** Number of rows in the result file, counted without loading it. */
function itResultLines(QueryRequest $request): int
{
    $handle = fopen(Storage::disk((string) $request->result_disk)->path((string) $request->result_path), 'rb');
    $lines = 0;

    while (fgets($handle) !== false) {
        $lines++;
    }

    fclose($handle);

    return $lines;
}

test('a 200,000 row result of 1 KB texts streams with a peak memory under 64 MB', function () {
    $this->it->createBig(200_000);

    $run = itRunInChild(
        $this->it,
        $this->it->request($this->it->connection(), 'SELECT id, payload FROM {big}'),
        ['QUERYPROXY_SELECT_HARD_LIMIT' => '200000'],
    );

    expect($run['request']->status)->toBe(QueryRequestStatus::Completed)
        ->and($run['request']->result_row_count)->toBe(200_000)
        ->and($run['request']->result_truncated)->toBeFalse()
        ->and(itResultLines($run['request']))->toBe(200_000)
        ->and($run['peak'])->toBeLessThan(itPeakLimit());

    itAssertResidentGrowth($run);
});

/** Bytes the MySQL or MariaDB server has sent to all clients so far. */
function itBytesSent(IntegrationTarget $it): int
{
    return (int) ((array) $it->admin()->selectOne("SHOW GLOBAL STATUS LIKE 'Bytes_sent'"))['Value'];
}

test('a read cut at the row ceiling stops early, the server is not asked to send the rest, and the memory stays bounded', function () {
    $this->it->createBig(200_000);

    $sentBefore = IntegrationTarget::isMysqlFamily() ? itBytesSent($this->it) : 0;

    $run = itRunInChild(
        $this->it,
        $this->it->request($this->it->connection(), 'SELECT id, payload FROM {big}'),
        ['QUERYPROXY_SELECT_HARD_LIMIT' => '10000'],
    );

    expect($run['request']->status)->toBe(QueryRequestStatus::Completed)
        ->and($run['request']->result_row_count)->toBe(10_000)
        ->and($run['request']->result_truncated)->toBeTrue()
        ->and(itResultLines($run['request']))->toBe(10_000)
        ->and($run['peak'])->toBeLessThan(itPeakLimit());

    itAssertResidentGrowth($run);

    // MySQL and MariaDB are asked to abort the statement at the ceiling
    // (KILL QUERY), so freeing the unbuffered result does not read the rest of
    // the table. The whole table is about 200 MB; the 10,000 rows kept are
    // about 10 MB. Reading the rest and throwing it away would show in the
    // server's byte count, however fast the local network makes that.
    if (IntegrationTarget::isMysqlFamily()) {
        $sent = itBytesSent($this->it) - $sentBefore;

        fwrite(STDERR, sprintf("[ceiling] %s: server sent %s MB for a 10,000 row cut of a 200 MB table\n", IntegrationTarget::driver()->value, number_format($sent / 1048576, 1)));

        expect($sent)->toBeLessThan(100 * 1024 * 1024);
    }
});

test('a SELECT the guard leaves without a LIMIT is still bounded by the row ceiling', function (string $userSql) {
    $this->it->createBig(30_000);

    $inspection = app(SqlInspector::class)->inspect($this->it->sql($userSql), IntegrationTarget::driver());

    expect($inspection->passes())->toBeTrue(implode('; ', $inspection->violations));

    $prepared = $inspection->preparedSql();
    $limited = preg_match('/\b(LIMIT|TOP|FETCH\s+FIRST)\b/i', $prepared) === 1;

    $run = itRunInChild(
        $this->it,
        $this->it->request($this->it->connection(), $prepared, attributes: ['sql_original' => $this->it->sql($userSql)]),
        ['QUERYPROXY_SELECT_HARD_LIMIT' => '10000'],
    );

    // The guard's default limit (1000) when it added one, the ceiling when it did not.
    expect($run['request']->status)->toBe(QueryRequestStatus::Completed)
        ->and($run['request']->result_row_count)->toBe($limited ? 1000 : 10_000)
        ->and($run['request']->result_truncated)->toBe(! $limited)
        ->and($run['peak'])->toBeLessThan(itPeakLimit());

    itAssertResidentGrowth($run);
})->with([
    'plain select' => ['SELECT id, payload FROM {big}'],
    'parenthesized select' => ['(SELECT id, payload FROM {big})'],
]);

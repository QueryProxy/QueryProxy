<?php

/*
 * Helper process for the integration tests: boots the application against the
 * application database named by DB_CONNECTION / DB_DATABASE in its environment
 * and runs one queued request through the real QueryExecutor, exactly as a
 * queue worker would. The test stops it from the outside, or reads the memory
 * it reports. Not a test file.
 *
 *   php tests/Integration/executor_process.php <query request id> <result directory>
 *
 * Output, one line each: "MEM base=<bytes> peak=<bytes> rss_anon_base=<bytes>"
 * and "EXECUTED" when the executor returned.
 *
 *  - base / peak: PHP's own memory_get_usage(true) before the request and
 *    memory_get_peak_usage(true) at the end (the figure ADR-003 names).
 *  - rss_anon_base: the process's anonymous resident memory (Linux, 0
 *    elsewhere) before the request. PHP's counter does not see what the client
 *    libraries allocate themselves, and libpq (pdo_pgsql) keeps a whole plain
 *    result in its own malloc'ed memory, so a PostgreSQL read that stopped
 *    using its cursor would still look small to PHP. The test samples the same
 *    figure from outside while the request runs (IntegrationTarget::
 *    waitSamplingMemory) and compares. Anonymous memory on purpose: the
 *    result file is copied to the store with a memory-mapped copy, which a
 *    "peak resident" figure would count as if it were a buffered result.
 */

use App\Models\QueryRequest;
use App\Services\Execution\QueryExecutor;
use Illuminate\Contracts\Console\Kernel;
use Tests\Integration\IntegrationTarget;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(['filesystems.disks.local.root' => (string) ($argv[2] ?? sys_get_temp_dir())]);

$request = QueryRequest::findOrFail((int) ($argv[1] ?? 0));
$base = memory_get_usage(true);
$rssBase = IntegrationTarget::anonymousMemory((int) getmypid());

$app->make(QueryExecutor::class)->execute($request);

fwrite(STDOUT, sprintf("MEM base=%d peak=%d rss_anon_base=%d\n", $base, memory_get_peak_usage(true), $rssBase));
fwrite(STDOUT, "EXECUTED\n");

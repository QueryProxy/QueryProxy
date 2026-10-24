<?php

/*
 * Helper process for PgsqlCancelTest: boots the application against the
 * application database named by DB_CONNECTION / DB_DATABASE in its
 * environment and runs one queued request through the real QueryExecutor,
 * exactly as a queue worker would. The test stops it from the outside. Not a
 * test file.
 *
 *   php tests/Live/pgsql_executor.php <query request id> <result directory>
 */

use App\Models\QueryRequest;
use App\Services\Execution\QueryExecutor;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(['filesystems.disks.local.root' => (string) ($argv[2] ?? sys_get_temp_dir())]);

$app->make(QueryExecutor::class)->execute(QueryRequest::findOrFail((int) ($argv[1] ?? 0)));

fwrite(STDOUT, "EXECUTED\n");

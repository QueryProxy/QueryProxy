<?php

/*
 * Helper process for AuditChainLiveTest: boots the application against the
 * live audit database (QUERYPROXY_LIVE_APPDB_*) and writes audit records, to
 * prove concurrent writers cannot fork the chain. Not a test file.
 *
 *   php tests/Live/audit_writer.php <records> <tag>
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Live\LiveAppDb;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(['database.connections.'.LiveAppDb::CONNECTION => LiveAppDb::connectionConfig()]);
DB::setDefaultConnection(LiveAppDb::CONNECTION);

$records = (int) ($argv[1] ?? 10);
$tag = (string) ($argv[2] ?? 'writer');

for ($i = 1; $i <= $records; $i++) {
    audit()->record('test.concurrent', metadata: ['writer' => $tag, 'i' => $i]);
}

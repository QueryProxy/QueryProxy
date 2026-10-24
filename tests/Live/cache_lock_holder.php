<?php

/*
 * Helper process for ConnectionSlotLiveTest: boots the application with the
 * database cache store pointed at the live application database
 * (QUERYPROXY_LIVE_APPDB_*), takes one cache lock and holds it, so the test
 * can try to take the same lock from another process. Not a test file.
 *
 *   php tests/Live/cache_lock_holder.php <lock name> <ttl seconds> <hold seconds>
 *
 * Output, one line each: "LOCKED" (or "BUSY" when somebody else holds it),
 * then "RELEASED" when it let go after the hold time.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Tests\Live\LiveAppDb;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

LiveAppDb::useForCache();

$lock = Cache::lock((string) ($argv[1] ?? 'holder'), (int) ($argv[2] ?? 60));

if (! $lock->get()) {
    fwrite(STDOUT, "BUSY\n");

    exit(0);
}

fwrite(STDOUT, "LOCKED\n");
fflush(STDOUT);

sleep((int) ($argv[3] ?? 30));

$lock->release();
fwrite(STDOUT, "RELEASED\n");

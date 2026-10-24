<?php

/*
 * Connection slots on a real application database (Faz 02): the slot a
 * request takes on a target connection is a Cache::lock on the database cache
 * store (ADR-002), which has to hold across worker processes. The Feature
 * suite checks the middleware with the array store inside one process; this
 * file checks the lock itself on the real database store, from two processes:
 * a held slot cannot be taken, the next one can, a released slot is free
 * again and a slot whose holder died frees itself when its TTL runs out.
 *
 * Runs only when QUERYPROXY_LIVE_APPDB_HOST is set (group "live-appdb"); see
 * AuditChainLiveTest.php for the environment.
 */

use App\Jobs\Middleware\LimitConnectionConcurrency;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Live\LiveAppDb;

beforeEach(function () {
    if (! LiveAppDb::configured()) {
        LiveAppDb::failIfRequired();
        $this->markTestSkipped('Set '.LiveAppDb::ENV_PREFIX.'HOST (and _DRIVER, _PORT, _DATABASE, _USERNAME, _PASSWORD) to run the live connection slot tests.');
    }

    $this->originalDefault = DB::getDefaultConnection();

    LiveAppDb::start();
    LiveAppDb::dropCacheTables();
    LiveAppDb::createCacheTables();
    LiveAppDb::useForCache();
});

afterEach(function () {
    if (LiveAppDb::configured() && isset($this->originalDefault)) {
        try {
            LiveAppDb::dropCacheTables();
        } finally {
            LiveAppDb::stop($this->originalDefault);
        }
    }
});

/** Start a process that holds the lock and wait until it says so. */
function liveSlotHolder(string $name, int $ttl, int $hold): Process
{
    $holder = new Process([PHP_BINARY, base_path('tests/Live/cache_lock_holder.php'), $name, (string) $ttl, (string) $hold], base_path());
    $holder->setTimeout($hold + 60)->start();

    $deadline = microtime(true) + 20;

    while (! str_contains($holder->getOutput(), 'LOCKED') && $holder->isRunning() && microtime(true) < $deadline) {
        usleep(100_000);
    }

    expect($holder->getOutput())->toContain('LOCKED', $holder->getErrorOutput());

    return $holder;
}

test('a slot held by another process cannot be taken, the next slot can', function () {
    $first = LimitConnectionConcurrency::lockName(7, 1);
    $second = LimitConnectionConcurrency::lockName(7, 2);

    $holder = liveSlotHolder($first, 60, 30);

    try {
        expect(Cache::lock($first, 60)->get())->toBeFalse();

        $lock = Cache::lock($second, 60);

        expect($lock->get())->toBeTrue();

        $lock->release();
    } finally {
        $holder->stop(1);
    }
});

test('a slot is free again once its holder released it', function () {
    $name = LimitConnectionConcurrency::lockName(7, 1);
    $holder = liveSlotHolder($name, 60, 2);

    try {
        expect(Cache::lock($name, 60)->get())->toBeFalse();

        $deadline = microtime(true) + 15;
        $lock = Cache::lock($name, 60);

        while (! ($acquired = $lock->get()) && microtime(true) < $deadline) {
            usleep(200_000);
        }

        expect($acquired)->toBeTrue();

        $lock->release();
    } finally {
        $holder->stop(1);
    }
});

test('a slot whose holder died frees itself when its TTL runs out', function () {
    $name = LimitConnectionConcurrency::lockName(7, 1);
    $holder = liveSlotHolder($name, 2, 60);

    try {
        expect(Cache::lock($name, 60)->get())->toBeFalse();

        $holder->stop(0, 9); // SIGKILL: it never gets to release the lock

        $deadline = microtime(true) + 10;
        $lock = Cache::lock($name, 60);

        while (! ($acquired = $lock->get()) && microtime(true) < $deadline) {
            usleep(200_000);
        }

        expect($acquired)->toBeTrue();

        $lock->release();
    } finally {
        if ($holder->isRunning()) {
            $holder->stop(0, 9);
        }
    }
});

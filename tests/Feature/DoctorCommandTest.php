<?php

use App\Services\Doctor\SchedulerHeartbeat;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    // Same setting as a running installation: the scheduler beats, the cache is shared.
    config(['cache.default' => 'database']);
    SchedulerHeartbeat::beat();
});

test('queryproxy:doctor exits 0 on a clean installation and prints a table', function () {
    $this->artisan('queryproxy:doctor')
        ->expectsOutputToContain('app_key')
        ->expectsOutputToContain('audit_chain')
        ->expectsOutputToContain('0 failure(s)')
        ->assertExitCode(0);
});

test('queryproxy:doctor exits 1 when a check fails', function () {
    config(['queryproxy.trusted_proxies' => ['*']]);

    $this->artisan('queryproxy:doctor')
        ->expectsOutputToContain('TRUSTED_PROXIES trusts every client')
        ->assertExitCode(1);
});

test('queryproxy:doctor lets warnings pass unless --strict is given', function () {
    config(['cache.default' => 'database']);
    Cache::forget(SchedulerHeartbeat::KEY);

    $this->artisan('queryproxy:doctor')->assertExitCode(0);
    $this->artisan('queryproxy:doctor', ['--strict' => true])->assertExitCode(1);
});

test('queryproxy:doctor --json prints machine readable results', function () {
    config(['queryproxy.trusted_proxies' => ['*']]);

    $exit = Artisan::call('queryproxy:doctor', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($payload['ok'])->toBeFalse()
        ->and(collect($payload['checks'])->firstWhere('check', 'trusted_proxies')['status'])->toBe('fail')
        ->and(collect($payload['checks'])->pluck('check'))->toContain('app_key', 'database', 'tls_enforcement', 'audit_chain');
});

test('queryproxy:doctor --json agrees with the exit code under --strict', function () {
    config(['cache.default' => 'database']);
    Cache::forget(SchedulerHeartbeat::KEY);

    $lenient = Artisan::call('queryproxy:doctor', ['--json' => true]);
    $lenientPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $strict = Artisan::call('queryproxy:doctor', ['--json' => true, '--strict' => true]);
    $strictPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($lenient)->toBe(0)
        ->and($lenientPayload['ok'])->toBeTrue()
        ->and($lenientPayload['strict'])->toBeFalse()
        ->and($strict)->toBe(1)
        ->and($strictPayload['ok'])->toBeFalse()
        ->and($strictPayload['strict'])->toBeTrue();
});

test('queryproxy:doctor --full-audit walks the whole audit chain', function () {
    audit()->record('test.one');
    audit()->record('test.two');

    $this->artisan('queryproxy:doctor', ['--full-audit' => true])
        ->expectsOutputToContain('Full verification: 2 records intact')
        ->assertExitCode(0);
});

test('queryproxy:doctor never prints secret values', function () {
    putenv('DB_PASSWORD=hunter2-very-secret');
    config(['queryproxy.siem.sinks' => ['webhook'], 'queryproxy.siem.webhook' => ['url' => 'https://siem.example.com/hook?token=abc123', 'secret' => 'short-one']]);

    Artisan::call('queryproxy:doctor');
    $output = Artisan::output();
    putenv('DB_PASSWORD');

    expect($output)->not->toContain('hunter2-very-secret')
        ->and($output)->not->toContain('abc123')
        ->and($output)->not->toContain('short-one')
        ->and($output)->not->toContain((string) config('app.key'));
});

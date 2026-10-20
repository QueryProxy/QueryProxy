<?php

use App\Enums\ChatSqlMode;
use App\Models\QueryRequest;
use App\Services\Chat\ChatNotifier;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Http;

const CHAT_TEST_SQL = "SELECT * FROM users WHERE email = 'ada@example.com' AND ssn = 123456789";

/** Post the submitted-message for a request with the given SQL and return the bodies sent to Slack and Teams. */
function chatNotifierBodies(string $sql, ?string $driver = 'pgsql'): array
{
    Http::fake();
    $before = Http::recorded()->count();

    $request = QueryRequest::first() ?? chatSetup()[3];
    $request->connection->update(['driver' => $driver]);
    $request->update(['sql_prepared' => $sql]);

    app(ChatNotifier::class)->requestSubmitted($request->fresh());

    $bodies = [];
    foreach (Http::recorded()->skip($before) as [$sent]) {
        $bodies[str_contains($sent->url(), 'slack') ? 'slack' : 'teams'] = $sent->body();
    }

    expect($bodies)->toHaveKeys(['slack', 'teams']);

    return $bodies;
}

test('ChatNotifier redacts literals by default', function () {
    config()->offsetUnset('queryproxy.chat_include_sql');
    expect(config('queryproxy.chat_include_sql'))->toBeNull();

    $bodies = chatNotifierBodies(CHAT_TEST_SQL);

    foreach ($bodies as $body) {
        expect($body)
            ->toContain('SELECT * FROM users WHERE email = ? AND ssn = ?')
            ->not->toContain('ada@example.com')
            ->not->toContain('123456789');
    }
});

test('ChatNotifier literals_redacted mode redacts for slack and teams', function () {
    config(['queryproxy.chat_include_sql' => 'literals_redacted']);

    $bodies = chatNotifierBodies(CHAT_TEST_SQL);

    expect($bodies)->toHaveKeys(['slack', 'teams']);
    foreach ($bodies as $body) {
        expect($body)->toContain('email = ? AND ssn = ?')->not->toContain('ada@example.com');
    }
});

test('ChatNotifier full mode sends the SQL as is', function () {
    config(['queryproxy.chat_include_sql' => 'full']);

    foreach (chatNotifierBodies(CHAT_TEST_SQL) as $body) {
        expect($body)->toContain('ada@example.com')->toContain('123456789');
    }
});

test('ChatNotifier none mode sends no SQL', function () {
    config(['queryproxy.chat_include_sql' => 'none']);

    foreach (chatNotifierBodies(CHAT_TEST_SQL) as $body) {
        expect($body)
            ->toContain('SQL preview disabled')
            ->not->toContain('ada@example.com')
            ->not->toContain('SELECT * FROM users');
    }
});

test('ChatNotifier legacy boolean true behaves as full and false as none', function () {
    config(['queryproxy.chat_include_sql' => true]);
    foreach (chatNotifierBodies(CHAT_TEST_SQL) as $body) {
        expect($body)->toContain('ada@example.com');
    }

    config(['queryproxy.chat_include_sql' => false]);
    foreach (chatNotifierBodies(CHAT_TEST_SQL) as $body) {
        expect($body)->not->toContain('ada@example.com')->not->toContain('SELECT * FROM users');
    }
});

test('ChatNotifier sends no SQL at all when the redactor cannot parse it', function () {
    config(['queryproxy.chat_include_sql' => 'literals_redacted']);

    foreach (chatNotifierBodies("SELECT * FROM users WHERE token = 'hunter2") as $body) {
        expect($body)->toContain('SQL hidden')->not->toContain('hunter2')->not->toContain('SELECT * FROM users');
    }
});

test('ChatNotifier truncates the redacted preview to 400 characters', function () {
    config(['queryproxy.chat_include_sql' => 'literals_redacted']);

    foreach (chatNotifierBodies('SELECT '.str_repeat('col, ', 300).'1 FROM t') as $body) {
        expect($body)->not->toContain('FROM t');
    }
});

test('ChatNotifier uses the connection driver to read the SQL', function () {
    config(['queryproxy.chat_include_sql' => 'literals_redacted']);

    // MySQL: double quotes are strings; PG: identifiers.
    foreach (chatNotifierBodies('SELECT "secret-value" FROM t', 'mysql') as $body) {
        expect($body)->not->toContain('secret-value');
    }
    foreach (chatNotifierBodies('SELECT "col_name" FROM t', 'pgsql') as $body) {
        expect($body)->toContain('col_name');
    }
});

test('ChatSqlMode maps the config values, defaulting to literals_redacted', function () {
    expect(ChatSqlMode::fromConfig(null))->toBe(ChatSqlMode::LiteralsRedacted)
        ->and(ChatSqlMode::fromConfig(''))->toBe(ChatSqlMode::LiteralsRedacted)
        ->and(ChatSqlMode::fromConfig('garbage'))->toBe(ChatSqlMode::LiteralsRedacted)
        ->and(ChatSqlMode::fromConfig('literals_redacted'))->toBe(ChatSqlMode::LiteralsRedacted)
        ->and(ChatSqlMode::fromConfig('full'))->toBe(ChatSqlMode::Full)
        ->and(ChatSqlMode::fromConfig(true))->toBe(ChatSqlMode::Full)
        ->and(ChatSqlMode::fromConfig('true'))->toBe(ChatSqlMode::Full)
        ->and(ChatSqlMode::fromConfig('1'))->toBe(ChatSqlMode::Full)
        ->and(ChatSqlMode::fromConfig('none'))->toBe(ChatSqlMode::None)
        ->and(ChatSqlMode::fromConfig(false))->toBe(ChatSqlMode::None)
        ->and(ChatSqlMode::fromConfig('false'))->toBe(ChatSqlMode::None)
        ->and(ChatSqlMode::fromConfig('0'))->toBe(ChatSqlMode::None);
});

test('ChatNotifier config file maps the legacy env booleans', function () {
    $resolve = function (mixed $env): string {
        putenv('QUERYPROXY_CHAT_INCLUDE_SQL'.($env === null ? '' : '='.$env));
        $_ENV['QUERYPROXY_CHAT_INCLUDE_SQL'] = $env;
        $_SERVER['QUERYPROXY_CHAT_INCLUDE_SQL'] = $env;
        if ($env === null) {
            unset($_ENV['QUERYPROXY_CHAT_INCLUDE_SQL'], $_SERVER['QUERYPROXY_CHAT_INCLUDE_SQL']);
        }
        Env::disablePutenv();

        return (require base_path('config/queryproxy.php'))['chat_include_sql'];
    };

    try {
        expect($resolve(null))->toBe('literals_redacted')
            ->and($resolve('true'))->toBe('full')
            ->and($resolve('false'))->toBe('none')
            ->and($resolve('full'))->toBe('full')
            ->and($resolve('none'))->toBe('none')
            ->and($resolve('literals_redacted'))->toBe('literals_redacted');
    } finally {
        unset($_ENV['QUERYPROXY_CHAT_INCLUDE_SQL'], $_SERVER['QUERYPROXY_CHAT_INCLUDE_SQL']);
        putenv('QUERYPROXY_CHAT_INCLUDE_SQL');
        Env::enablePutenv();
    }
});

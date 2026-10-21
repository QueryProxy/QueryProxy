<?php

use App\Enums\AuditEvent;
use App\Jobs\ExecuteQueryRequest;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use App\Services\Audit\Sinks\AuditEventFormatter;
use App\Services\Audit\Sinks\DeliverAuditEvent;
use App\Services\Audit\Sinks\SinkDeliveryException;
use App\Services\Audit\Sinks\SinkRegistry;
use App\Services\Audit\Sinks\StdoutSink;
use App\Services\Audit\Sinks\SyslogSink;
use App\Services\Audit\Sinks\WebhookSink;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\JsonSchema;

const TEST_WEBHOOK_SECRET = 'a-long-enough-shared-secret';

function sinkEvent(array $metadata = ['k' => 'v']): array
{
    $log = audit()->record(AuditEvent::AuditCheckpoint, metadata: $metadata);

    return app(AuditEventFormatter::class)->format($log);
}

function webhookSink(array $overrides = []): WebhookSink
{
    return new WebhookSink(app(AuditEventFormatter::class), $overrides + [
        'url' => 'https://siem.example.test/ingest/s3cr3t-path-token?key=abc',
        'secret' => TEST_WEBHOOK_SECRET,
        'timeout' => 5,
        'tries' => 6,
    ]);
}

function enableSinks(string $sinks, array $config = []): void
{
    config(['queryproxy.siem.sinks' => array_values(array_filter(explode(',', $sinks)))]);

    foreach ($config as $key => $value) {
        config(["queryproxy.siem.{$key}" => $value]);
    }
}

// --- webhook --------------------------------------------------------------

test('the webhook body is signed so a receiver can verify it', function () {
    Http::fake(['siem.example.test/*' => Http::response('', 204)]);

    $event = sinkEvent();
    webhookSink()->deliver($event);

    Http::assertSent(function (Request $request) use ($event) {
        $timestamp = $request->header('X-QueryProxy-Timestamp')[0];
        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), TEST_WEBHOOK_SECRET);

        return $request->method() === 'POST'
            && $request->url() === 'https://siem.example.test/ingest/s3cr3t-path-token?key=abc'
            && hash_equals($expected, $request->header('X-QueryProxy-Signature')[0])
            && abs((int) $timestamp - time()) <= 5
            && $request->header('X-QueryProxy-Event')[0] === 'audit.checkpoint'
            && $request->header('X-QueryProxy-Event-Id')[0] === (string) $event['id']
            && $request->header('Content-Type')[0] === 'application/json'
            && json_decode($request->body(), true) === json_decode(app(AuditEventFormatter::class)->json($event), true);
    });
});

test('a tampered body or a wrong secret does not verify', function () {
    $sink = webhookSink();
    $body = '{"id":1}';
    $signature = $sink->signature('1700000000', $body);

    expect($signature)->toMatch('/^sha256=[0-9a-f]{64}$/')
        ->and($signature)->not->toBe($sink->signature('1700000000', '{"id":2}'))
        ->and($signature)->not->toBe($sink->signature('1700000001', $body))
        ->and($signature)->not->toBe(webhookSink(['secret' => 'another-long-enough-secret'])->signature('1700000000', $body));
});

test('a non-2xx webhook answer is a failure without the URL in the error', function () {
    Http::fake(['*' => Http::response('go away s3cr3t-path-token', 500)]);

    try {
        webhookSink()->deliver(sinkEvent());
        $this->fail('Expected a SinkDeliveryException.');
    } catch (SinkDeliveryException $e) {
        expect($e->getMessage())->toContain('HTTP 500')
            ->and($e->getMessage())->not->toContain('s3cr3t')
            ->and($e->getPrevious())->toBeNull();
    }
});

test('a webhook transport error is a failure without the URL in the error', function () {
    Http::fake(fn () => throw new ConnectionException(
        'cURL error 6: Could not resolve host for https://siem.example.test/ingest/s3cr3t-path-token?key=abc'
    ));

    try {
        webhookSink()->deliver(sinkEvent());
        $this->fail('Expected a SinkDeliveryException.');
    } catch (SinkDeliveryException $e) {
        expect($e->getMessage())->toContain('request failed')
            ->and($e->getMessage())->not->toContain('s3cr3t')
            ->and($e->getMessage())->not->toContain('siem.example.test')
            ->and($e->getPrevious())->toBeNull();
    }
});

test('a redirect is not followed', function () {
    Http::fake(['*' => Http::response('', 302, ['Location' => 'https://elsewhere.example.test/'])]);

    expect(fn () => webhookSink()->deliver(sinkEvent()))->toThrow(SinkDeliveryException::class);

    Http::assertSentCount(1);
});

test('the webhook sink refuses an unsigned or malformed setup', function () {
    $ok = ['url' => 'https://siem.example.test/in', 'secret' => TEST_WEBHOOK_SECRET];

    expect(WebhookSink::configurationProblem($ok))->toBeNull()
        ->and(WebhookSink::configurationProblem(['secret' => ''] + $ok))->toContain('SECRET')
        ->and(WebhookSink::configurationProblem(['secret' => 'short'] + $ok))->toContain('SECRET')
        ->and(WebhookSink::configurationProblem(['url' => ''] + $ok))->toContain('URL')
        ->and(WebhookSink::configurationProblem(['url' => 'ftp://x.test/'] + $ok))->toContain('URL')
        ->and(WebhookSink::configurationProblem(['url' => 'not a url'] + $ok))->toContain('URL');
});

// --- stdout ---------------------------------------------------------------

test('the stdout sink writes one JSON line per event', function () {
    $file = tempnam(sys_get_temp_dir(), 'siem');
    $sink = new StdoutSink(app(AuditEventFormatter::class), $file);

    try {
        $first = sinkEvent(['name' => "multi\nline ünï"]);
        $second = sinkEvent();
        $sink->deliver($first);
        $sink->deliver($second);

        $lines = explode("\n", rtrim((string) file_get_contents($file), "\n"));
        $schema = JsonSchema::load(base_path('docs/audit-event.schema.json'));

        expect($lines)->toHaveCount(2)
            ->and(json_decode($lines[0], true))->toBe(json_decode(json_encode($first), true))
            ->and(JsonSchema::validate(json_decode($lines[1], true), $schema))->toBe([]);
    } finally {
        @unlink($file);
    }
});

test('the stdout sink reports an unwritable stream', function () {
    $sink = new StdoutSink(app(AuditEventFormatter::class), '/nonexistent-dir-for-siem/out.log');

    expect(fn () => $sink->deliver(sinkEvent()))->toThrow(SinkDeliveryException::class);
});

// --- syslog ---------------------------------------------------------------

function syslogConfig(array $overrides = []): array
{
    return $overrides + [
        'host' => '127.0.0.1',
        'port' => 514,
        'transport' => 'udp',
        'framing' => 'octet-counting',
        'facility' => 13,
        'app_name' => 'queryproxy',
        'hostname' => 'qp-test',
        'tls_ca_file' => '',
        'timeout' => 2,
    ];
}

test('the syslog message is RFC 5424 with the JSON event as its body', function () {
    $event = sinkEvent();
    $message = (new SyslogSink(app(AuditEventFormatter::class), syslogConfig()))->message($event);

    expect($message)->toStartWith('<110>1 '.$event['timestamp'].' qp-test queryproxy - audit.checkpoint - {')
        ->and(json_decode(substr($message, strpos($message, ' - {') + 3), true))
        ->toBe(json_decode(json_encode($event), true));
});

test('syslog header fields cannot be used to inject structure', function () {
    $sink = new SyslogSink(app(AuditEventFormatter::class), syslogConfig(['hostname' => "evil host\n<13>1", 'app_name' => 'a b']));
    $header = explode(' - ', $sink->message(sinkEvent()), 2)[0];

    expect($header)->not->toContain("\n")
        ->and(count(explode(' ', $header)))->toBe(4);
});

test('a syslog event arrives over UDP', function () {
    $server = stream_socket_server('udp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
    expect($server)->not->toBeFalse();
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);

    $event = sinkEvent();
    (new SyslogSink(app(AuditEventFormatter::class), syslogConfig(['port' => $port])))->deliver($event);

    stream_set_timeout($server, 2);
    $datagram = stream_socket_recvfrom($server, 65535);
    fclose($server);

    expect($datagram)->toStartWith('<110>1 ')->and($datagram)->toContain('"id":'.$event['id']);
});

test('a syslog event arrives over TCP with octet counting or newline framing', function (string $framing) {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    expect($server)->not->toBeFalse();
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);

    $event = sinkEvent();
    (new SyslogSink(app(AuditEventFormatter::class), syslogConfig(['port' => $port, 'transport' => 'tcp', 'framing' => $framing])))->deliver($event);

    $client = stream_socket_accept($server, 2);
    $data = stream_get_contents($client);
    fclose($client);
    fclose($server);

    if ($framing === 'newline') {
        expect($data)->toEndWith("}\n")->and(substr_count($data, "\n"))->toBe(1);
    } else {
        [$length, $message] = explode(' ', $data, 2);
        expect((int) $length)->toBe(strlen($message))->and($message)->toStartWith('<110>1 ');
    }
})->with(['octet-counting', 'newline']);

test('syslog over TLS always verifies the peer', function () {
    $sink = new SyslogSink(app(AuditEventFormatter::class), syslogConfig(['host' => 'logs.example.test', 'port' => 6514, 'transport' => 'tls', 'tls_ca_file' => '/etc/ca.pem']));

    expect($sink->address())->toBe('tls://logs.example.test:6514')
        ->and($sink->contextOptions()['ssl'])->toMatchArray([
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => 'logs.example.test',
            'allow_self_signed' => false,
            'cafile' => '/etc/ca.pem',
        ]);
});

test('syslog addresses wrap IPv6 hosts', function () {
    $sink = new SyslogSink(app(AuditEventFormatter::class), syslogConfig(['host' => '2001:db8::1', 'transport' => 'tcp', 'port' => 601]));

    expect($sink->address())->toBe('tcp://[2001:db8::1]:601');
});

test('an unreachable syslog server is a delivery failure', function () {
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
    fclose($server);

    $sink = new SyslogSink(app(AuditEventFormatter::class), syslogConfig(['port' => $port, 'transport' => 'tcp']));

    expect(fn () => $sink->deliver(sinkEvent()))->toThrow(SinkDeliveryException::class);
});

test('the syslog sink validates its configuration', function () {
    expect(SyslogSink::configurationProblem(syslogConfig()))->toBeNull()
        ->and(SyslogSink::configurationProblem(syslogConfig(['host' => ''])))->toContain('HOST')
        ->and(SyslogSink::configurationProblem(syslogConfig(['transport' => 'http'])))->toContain('TRANSPORT')
        ->and(SyslogSink::configurationProblem(syslogConfig(['framing' => 'x'])))->toContain('FRAMING')
        ->and(SyslogSink::configurationProblem(syslogConfig(['port' => 0])))->toContain('PORT')
        ->and(SyslogSink::configurationProblem(syslogConfig(['port' => 70000])))->toContain('PORT')
        ->and(SyslogSink::configurationProblem(syslogConfig(['facility' => 24])))->toContain('FACILITY');
});

// --- registry -------------------------------------------------------------

test('all sinks are off by default', function () {
    expect(config('queryproxy.siem.sinks'))->toBe([])
        ->and(app(SinkRegistry::class)->enabledNames())->toBe([]);
});

test('the registry builds only the sinks that are on and usable', function () {
    Log::spy();
    SinkRegistry::forgetReports();
    enableSinks('stdout,webhook,syslog,bogus', [
        'webhook' => ['url' => '', 'secret' => ''],
        'syslog' => syslogConfig(),
    ]);

    $registry = app(SinkRegistry::class);

    expect($registry->enabledNames())->toBe(['stdout', 'syslog'])
        ->and($registry->make('webhook'))->toBeNull()
        ->and($registry->make('stdout'))->toBeInstanceOf(StdoutSink::class);

    // The misconfigured and unknown entries are reported, once each.
    $registry->enabledNames();
    Log::shouldHaveReceived('warning')->times(2);
});

// --- dispatch and delivery ------------------------------------------------

test('no job is queued while every sink is off', function () {
    Queue::fake();

    audit()->record(AuditEvent::AuditCheckpoint);

    Queue::assertNothingPushed();
});

test('one job per enabled sink is queued for a recorded event', function () {
    Queue::fake();
    enableSinks('stdout,webhook', ['webhook' => ['url' => 'https://siem.example.test/in', 'secret' => TEST_WEBHOOK_SECRET]]);

    $log = audit()->record(AuditEvent::AuditCheckpoint);

    Queue::assertPushed(DeliverAuditEvent::class, 2);
    Queue::assertPushed(DeliverAuditEvent::class, fn ($job) => $job->sink === 'stdout' && $job->auditLogId === $log->id && $job->queue === 'default');
    Queue::assertPushed(DeliverAuditEvent::class, fn ($job) => $job->sink === 'webhook' && $job->auditLogId === $log->id);
});

test('the queue name is configurable', function () {
    Queue::fake();
    enableSinks('stdout', ['queue' => 'siem']);

    audit()->record(AuditEvent::AuditCheckpoint);

    Queue::assertPushedOn('siem', DeliverAuditEvent::class);
});

test('the queued job carries no event data', function () {
    Queue::fake();
    enableSinks('stdout');

    audit()->record(AuditEvent::AuditCheckpoint, sql: "SELECT 'topsecretvalue'", metadata: ['token' => 'topsecretvalue']);

    Queue::assertPushed(DeliverAuditEvent::class, fn ($job) => ! str_contains(serialize($job), 'topsecretvalue'));
});

test('delivery waits for the surrounding transaction and is dropped on rollback', function () {
    Queue::fake();
    enableSinks('stdout');

    try {
        DB::transaction(function () {
            audit()->record(AuditEvent::AuditCheckpoint);
            Queue::assertNothingPushed();

            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }

    Queue::assertNothingPushed();

    DB::transaction(function () {
        audit()->record(AuditEvent::AuditCheckpoint);
        Queue::assertNothingPushed();
    });

    Queue::assertPushed(DeliverAuditEvent::class, 1);
});

test('the job delivers the stored event to its sink', function () {
    $file = tempnam(sys_get_temp_dir(), 'siem');
    enableSinks('stdout', ['stdout' => ['stream' => $file]]);

    try {
        $log = audit()->record(AuditEvent::AuditCheckpoint, metadata: ['n' => 1]);

        // QUEUE_CONNECTION=sync already ran it; run it once more to see a redelivery repeats the id.
        dispatch(new DeliverAuditEvent('stdout', $log->id));

        $lines = array_map(fn ($l) => json_decode($l, true), explode("\n", trim((string) file_get_contents($file))));

        expect($lines)->toHaveCount(2)
            ->and(array_column($lines, 'id'))->toBe([$log->id, $log->id])
            ->and($lines[0]['metadata'])->toBe(['n' => 1]);
    } finally {
        @unlink($file);
    }
});

test('the job does nothing when its sink was switched off', function () {
    $log = audit()->record(AuditEvent::AuditCheckpoint);
    Http::fake();

    dispatch(new DeliverAuditEvent('webhook', $log->id));

    Http::assertNothingSent();
});

test('the job takes its retry policy from the sink', function () {
    enableSinks('webhook,syslog,stdout', [
        'webhook' => ['url' => 'https://siem.example.test/in', 'secret' => TEST_WEBHOOK_SECRET, 'tries' => 4],
        'syslog' => syslogConfig(),
    ]);

    $webhook = new DeliverAuditEvent('webhook', 1);
    $syslog = new DeliverAuditEvent('syslog', 1);

    expect($webhook->tries)->toBe(4)
        ->and($webhook->backoff())->toBe([10, 60, 300, 900, 3600])
        ->and($syslog->tries)->toBe(4)
        ->and((new DeliverAuditEvent('stdout', 1))->tries)->toBe(3);
});

test('a failing sink does not block or undo the audit record', function () {
    enableSinks('webhook', ['webhook' => ['url' => 'https://siem.example.test/in', 'secret' => TEST_WEBHOOK_SECRET]]);
    Http::fake(['*' => Http::response('down', 503)]);

    // QUEUE_CONNECTION=sync runs the job inline and the delivery fails; the audit write must survive.
    $log = null;
    expect(function () use (&$log) {
        $log = audit()->record(AuditEvent::AuditCheckpoint);
    })->not->toThrow(Throwable::class);

    expect(AuditLog::whereKey($log->id)->exists())->toBeTrue();
});

test('a queue that is down does not break the audit write', function () {
    Log::spy();
    enableSinks('stdout');

    $this->app->bind(Illuminate\Contracts\Queue\Queue::class, fn () => throw new RuntimeException('queue backend is down'));
    $this->app['queue']->extend('broken', fn () => throw new RuntimeException('queue backend is down'));
    config(['queue.default' => 'broken', 'queue.connections.broken' => ['driver' => 'broken']]);

    $log = audit()->record(AuditEvent::AuditCheckpoint);

    expect(AuditLog::whereKey($log->id)->exists())->toBeTrue();
    Log::shouldHaveReceived('error')->withArgs(fn ($message) => str_contains($message, 'could not queue'))->once();
});

test('a failure while reading the sink configuration does not break the audit write', function () {
    $this->app->bind(SinkRegistry::class, fn () => throw new RuntimeException('boom'));
    Log::spy();

    $log = audit()->record(AuditEvent::AuditCheckpoint);

    expect(AuditLog::whereKey($log->id)->exists())->toBeTrue();
});

test('the failed hook logs the sink without the URL or secret', function () {
    Log::spy();
    $job = new DeliverAuditEvent('webhook', 5);

    $job->failed(new SinkDeliveryException('webhook sink: the receiver answered HTTP 500.'));

    Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => $context['sink'] === 'webhook'
        && $context['audit_log_id'] === 5
        && ! str_contains(json_encode($context), 'http'));
});

test('a misconfigured sink is reported once, not on every audit write', function () {
    Log::spy();
    Queue::fake();
    SinkRegistry::forgetReports();
    enableSinks('webhook,stdot', ['webhook' => ['url' => 'https://siem.example.test/in', 'secret' => 'short']]);

    audit()->record(AuditEvent::AuditCheckpoint);
    audit()->record(AuditEvent::AuditCheckpoint);
    audit()->record(AuditEvent::AuditCheckpoint);

    // One warning for the weak secret, one for the unknown name; nothing queued.
    Log::shouldHaveReceived('warning')->times(2);
    Queue::assertNothingPushed();
});

test('syslog over TLS defaults to port 6514, the other transports to 514', function (string $transport, ?string $port, int $expected) {
    $previous = [getenv('QUERYPROXY_SIEM_SYSLOG_TRANSPORT'), getenv('QUERYPROXY_SIEM_SYSLOG_PORT')];

    putenv("QUERYPROXY_SIEM_SYSLOG_TRANSPORT={$transport}");
    $port === null ? putenv('QUERYPROXY_SIEM_SYSLOG_PORT') : putenv("QUERYPROXY_SIEM_SYSLOG_PORT={$port}");

    try {
        $config = include config_path('queryproxy.php');
    } finally {
        foreach (['QUERYPROXY_SIEM_SYSLOG_TRANSPORT', 'QUERYPROXY_SIEM_SYSLOG_PORT'] as $i => $name) {
            $value = $previous[$i];
            $value === false ? putenv($name) : putenv("{$name}={$value}");
        }
    }

    expect($config['siem']['syslog']['port'])->toBe($expected);
})->with([
    'tls' => ['tls', null, 6514],
    'tcp' => ['tcp', null, 514],
    'udp' => ['udp', null, 514],
    'explicit port wins' => ['tls', '7000', 7000],
]);

test('approving a request sends a signed, schema-conforming webhook', function () {
    Queue::fake([ExecuteQueryRequest::class]);
    Notification::fake();
    Http::fake(['siem.example.test/*' => Http::response('', 200)]);
    enableSinks('webhook', ['webhook' => ['url' => 'https://siem.example.test/in', 'secret' => TEST_WEBHOOK_SECRET]]);

    $team = Team::factory()->create();
    $dba = User::factory()->create();
    $developer = User::factory()->create();
    $team->users()->attach($dba, ['role' => 'dba']);
    $team->users()->attach($developer, ['role' => 'developer']);
    $connection = Connection::factory()->create(['team_id' => $team->id]);
    $queryRequest = QueryRequest::factory()->create([
        'team_id' => $team->id,
        'connection_id' => $connection->id,
        'user_id' => $developer->id,
    ]);

    app(ApprovalService::class)->approve($queryRequest, $dba);

    $schema = JsonSchema::load(base_path('docs/audit-event.schema.json'));
    $sent = [];

    Http::assertSent(function (Request $request) use (&$sent, $schema, $queryRequest, $dba) {
        $body = json_decode($request->body(), true);

        if ($body['action'] !== 'request.approved') {
            return false;
        }

        $timestamp = $request->header('X-QueryProxy-Timestamp')[0];
        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), TEST_WEBHOOK_SECRET);

        $sent[] = $body;

        return hash_equals($expected, $request->header('X-QueryProxy-Signature')[0])
            && JsonSchema::validate($body, $schema) === []
            && $body['query_request_id'] === $queryRequest->id
            && $body['actor']['id'] === $dba->id
            && $body['metadata']['override'] === false;
    });

    expect($sent)->toHaveCount(1);
});

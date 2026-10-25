<?php

use App\Models\Connection;
use App\Models\MaskingRule;
use App\Models\Team;
use App\Services\Audit\AuditChain;
use App\Services\Audit\AuditTriggers;
use App\Services\Audit\AuditVerifier;
use App\Services\Connections\TlsPemStore;
use App\Services\Doctor\Check;
use App\Services\Doctor\CheckResult;
use App\Services\Doctor\Checks\AppEnvironmentCheck;
use App\Services\Doctor\Checks\AppKeyCheck;
use App\Services\Doctor\Checks\AppKeyLocationCheck;
use App\Services\Doctor\Checks\AuditChainCheck;
use App\Services\Doctor\Checks\DatabaseCheck;
use App\Services\Doctor\Checks\FailedJobsCheck;
use App\Services\Doctor\Checks\MaskingCoverageCheck;
use App\Services\Doctor\Checks\MetricsCheck;
use App\Services\Doctor\Checks\QueueTimingCheck;
use App\Services\Doctor\Checks\ResultDiskCheck;
use App\Services\Doctor\Checks\SchedulerCheck;
use App\Services\Doctor\Checks\SecretFilesCheck;
use App\Services\Doctor\Checks\SiemCheck;
use App\Services\Doctor\Checks\SqliteAllowedDirCheck;
use App\Services\Doctor\Checks\StoragePermissionsCheck;
use App\Services\Doctor\Checks\TlsEnforcementCheck;
use App\Services\Doctor\Checks\TlsFilesCheck;
use App\Services\Doctor\Checks\TrustedProxiesCheck;
use App\Services\Doctor\Checks\WorkerCheck;
use App\Services\Doctor\CheckStatus;
use App\Services\Doctor\Doctor;
use App\Services\Doctor\SchedulerHeartbeat;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** One fresh instance per call: checks keep no state. */
function doctorRun(Check $check): CheckResult
{
    return $check->run();
}

function doctorAuditCheck(bool $full = false): AuditChainCheck
{
    return new AuditChainCheck(new AuditChain, app(AuditVerifier::class), $full);
}

function doctorTempDir(): string
{
    $directory = rtrim((string) (getenv('TMPDIR') ?: sys_get_temp_dir()), '/').'/qp-doctor-'.bin2hex(random_bytes(4));
    mkdir($directory, 0700, true);

    return $directory;
}

function doctorRemoveDir(string $directory): void
{
    @chmod($directory, 0700);

    foreach (glob($directory.'/{,.}*', GLOB_BRACE) ?: [] as $entry) {
        if (basename($entry) !== '.' && basename($entry) !== '..') {
            is_dir($entry) ? doctorRemoveDir($entry) : @unlink($entry);
        }
    }

    @rmdir($directory);
}

afterEach(function () {
    config(['database.default' => 'sqlite']);

    // DDL may not roll back on every driver: always leave the triggers in place.
    $triggers = new AuditTriggers;
    $triggers->drop();
    $triggers->install();

    doctorRemoveDir(storage_path('app/tls'));
    putenv('APP_KEY_FILE');
    putenv('DB_PASSWORD_FILE');
    putenv('DB_PASSWORD');
    putenv('QUERYPROXY_METRICS_TOKEN');
    putenv('QUERYPROXY_METRICS_TOKEN_FILE');
});

// --- app_key ---------------------------------------------------------------

test('Doctor app_key accepts a valid key', function () {
    expect(doctorRun(new AppKeyCheck)->status)->toBe(CheckStatus::Ok);
});

test('Doctor app_key fails when the key is missing', function () {
    config(['app.key' => '']);

    expect(doctorRun(new AppKeyCheck)->status)->toBe(CheckStatus::Fail);
});

test('Doctor app_key fails on a key of the wrong length', function () {
    config(['app.key' => 'base64:'.base64_encode('too-short')]);

    $result = doctorRun(new AppKeyCheck);

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->message)->toContain('not a valid key');
});

test('Doctor app_key fails when stored credentials no longer decrypt', function () {
    Connection::factory()->create();

    config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');

    $result = doctorRun(new AppKeyCheck);

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->message)->toContain('APP_PREVIOUS_KEYS');
});

// --- app_key_location ------------------------------------------------------

function doctorKeyLocation(string $keyFile, callable $device): AppKeyLocationCheck
{
    return new class($keyFile, $device) extends AppKeyLocationCheck
    {
        public function __construct(private readonly string $keyFile, private $device) {}

        protected function candidates(): array
        {
            return [$this->keyFile];
        }

        protected function deviceOf(string $path): ?int
        {
            return ($this->device)($path);
        }
    };
}

test('Doctor app_key_location skips when the database is not a SQLite file', function () {
    expect(doctorRun(new AppKeyLocationCheck)->status)->toBe(CheckStatus::Skip);
});

test('Doctor app_key_location warns when key file and SQLite database share a volume', function () {
    $directory = doctorTempDir();
    touch($directory.'/db.sqlite');
    file_put_contents($directory.'/.app_key', config('app.key')."\n");
    config(['database.connections.sqlite.database' => $directory.'/db.sqlite']);

    $result = doctorRun(doctorKeyLocation($directory.'/.app_key', fn () => 42));

    expect($result->status)->toBe(CheckStatus::Warn)
        ->and($result->message)->toContain('same volume');

    doctorRemoveDir($directory);
});

test('Doctor app_key_location is fine when the key file is on another volume', function () {
    $directory = doctorTempDir();
    touch($directory.'/db.sqlite');
    file_put_contents($directory.'/.app_key', config('app.key'));
    config(['database.connections.sqlite.database' => $directory.'/db.sqlite']);

    $result = doctorRun(doctorKeyLocation($directory.'/.app_key', fn (string $path) => str_ends_with($path, '.app_key') ? 2 : 1));

    expect($result->status)->toBe(CheckStatus::Ok);

    doctorRemoveDir($directory);
});

test('Doctor app_key_location ignores a key file that is not the key in use', function () {
    $directory = doctorTempDir();
    touch($directory.'/db.sqlite');
    file_put_contents($directory.'/.app_key', 'base64:another-key');
    config(['database.connections.sqlite.database' => $directory.'/db.sqlite']);

    $result = doctorRun(doctorKeyLocation($directory.'/.app_key', fn () => 42));

    expect($result->status)->toBe(CheckStatus::Ok)
        ->and($result->message)->toContain('does not come from a file');

    doctorRemoveDir($directory);
});

// --- secret_files ----------------------------------------------------------

test('Doctor secret_files is quiet when no *_FILE is set', function () {
    expect(doctorRun(new SecretFilesCheck)->status)->toBe(CheckStatus::Ok);
});

test('Doctor secret_files reports a conflict and an unreadable file', function () {
    putenv('DB_PASSWORD=plain');
    putenv('DB_PASSWORD_FILE=/nonexistent/secret');

    $result = doctorRun(new SecretFilesCheck);

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->message)->toContain('Both DB_PASSWORD and DB_PASSWORD_FILE')
        ->and($result->message)->not->toContain('plain');
});

test('Doctor secret_files lists the variables read from files', function () {
    $directory = doctorTempDir();
    file_put_contents($directory.'/pw', "s3cr3t\n");
    putenv('DB_PASSWORD_FILE='.$directory.'/pw');

    $result = doctorRun(new SecretFilesCheck);

    expect($result->status)->toBe(CheckStatus::Ok)
        ->and($result->message)->toContain('DB_PASSWORD')
        ->and($result->message)->not->toContain('s3cr3t');

    doctorRemoveDir($directory);
});

// --- app_environment -------------------------------------------------------

test('Doctor app_environment fails on debug in production, warns elsewhere', function () {
    config(['app.env' => 'production', 'app.debug' => true]);
    expect(doctorRun(new AppEnvironmentCheck)->status)->toBe(CheckStatus::Fail);

    config(['app.env' => 'local', 'app.debug' => true]);
    expect(doctorRun(new AppEnvironmentCheck)->status)->toBe(CheckStatus::Warn);

    config(['app.env' => 'production', 'app.debug' => false]);
    expect(doctorRun(new AppEnvironmentCheck)->status)->toBe(CheckStatus::Ok);
});

// --- database --------------------------------------------------------------

test('Doctor database passes with all migrations applied', function () {
    expect(doctorRun(new DatabaseCheck)->status)->toBe(CheckStatus::Ok);
});

test('Doctor database fails on pending migrations', function () {
    DB::table('migrations')->delete();

    $result = doctorRun(new DatabaseCheck);

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->message)->toContain('migration(s) not applied');
});

test('Doctor database fails when the database is unreachable', function () {
    config([
        'database.default' => 'doctor_broken',
        'database.connections.doctor_broken' => ['driver' => 'sqlite', 'database' => '/nonexistent/dir/db.sqlite', 'prefix' => ''],
    ]);

    $result = doctorRun(new DatabaseCheck);

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->message)->toContain('Cannot connect');
});

// --- queue_timing ----------------------------------------------------------

test('Doctor queue_timing compares retry_after with the worker timeout', function () {
    config(['queue.default' => 'database', 'queryproxy.execution_timeout' => 300]);

    config(['queue.connections.database.retry_after' => 310]);
    expect(doctorRun(new QueueTimingCheck)->status)->toBe(CheckStatus::Fail);

    config(['queue.connections.database.retry_after' => 330]);
    expect(doctorRun(new QueueTimingCheck)->status)->toBe(CheckStatus::Ok);
});

test('Doctor queue_timing ignores QUERYPROXY_WORKER_TIMEOUT, as the entrypoint does', function () {
    config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 330, 'queryproxy.execution_timeout' => 300]);
    putenv('QUERYPROXY_WORKER_TIMEOUT=600');

    try {
        expect(doctorRun(new QueueTimingCheck)->status)->toBe(CheckStatus::Ok);
    } finally {
        putenv('QUERYPROXY_WORKER_TIMEOUT');
    }
});

test('Doctor queue_timing fails when retry_after does not exceed the execution timeout', function () {
    config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 60, 'queryproxy.execution_timeout' => 300]);

    expect(doctorRun(new QueueTimingCheck)->status)->toBe(CheckStatus::Fail);
});

test('Doctor queue_timing skips the sync queue', function () {
    config(['queue.default' => 'sync']);

    expect(doctorRun(new QueueTimingCheck)->status)->toBe(CheckStatus::Skip);
});

// --- worker ----------------------------------------------------------------

function doctorQueueJob(int $availableAt, ?int $reservedAt = null): void
{
    DB::table('jobs')->insert([
        'queue' => 'queries', 'payload' => '{}', 'attempts' => 0,
        'reserved_at' => $reservedAt, 'available_at' => $availableAt, 'created_at' => $availableAt,
    ]);
}

test('Doctor worker warns about jobs nobody picks up', function () {
    config(['queue.default' => 'database', 'queryproxy.execution_timeout' => 300]);
    doctorQueueJob(now()->subHour()->getTimestamp());

    $result = doctorRun(new WorkerCheck);

    expect($result->status)->toBe(CheckStatus::Warn)
        ->and($result->message)->toContain('1 job(s)');
});

test('Doctor worker passes for fresh or running jobs', function () {
    config(['queue.default' => 'database', 'queryproxy.execution_timeout' => 300]);
    doctorQueueJob(now()->subSeconds(30)->getTimestamp());
    doctorQueueJob(now()->subHour()->getTimestamp(), reservedAt: now()->subMinute()->getTimestamp());

    expect(doctorRun(new WorkerCheck)->status)->toBe(CheckStatus::Ok);
});

test('Doctor worker skips queues other than the database queue', function () {
    config(['queue.default' => 'sync']);

    expect(doctorRun(new WorkerCheck)->status)->toBe(CheckStatus::Skip);
});

// --- scheduler -------------------------------------------------------------

test('Doctor scheduler follows the heartbeat', function () {
    config(['cache.default' => 'database']);

    expect(doctorRun(new SchedulerCheck)->status)->toBe(CheckStatus::Warn);

    SchedulerHeartbeat::beat();
    expect(doctorRun(new SchedulerCheck)->status)->toBe(CheckStatus::Ok);

    $this->travel(10)->minutes();
    $result = doctorRun(new SchedulerCheck);
    expect($result->status)->toBe(CheckStatus::Warn)
        ->and($result->message)->toContain('stopped');
});

test('Doctor scheduler skips the array cache', function () {
    config(['cache.default' => 'array']);

    expect(doctorRun(new SchedulerCheck)->status)->toBe(CheckStatus::Skip);
});

test('Doctor scheduler heartbeat is registered every minute', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'scheduler-heartbeat');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *');
});

// --- failed_jobs -----------------------------------------------------------

test('Doctor failed_jobs counts failed jobs', function () {
    expect(doctorRun(new FailedJobsCheck)->status)->toBe(CheckStatus::Ok);

    DB::table('failed_jobs')->insert(['uuid' => 'a', 'connection' => 'database', 'queue' => 'queries', 'payload' => '{}', 'exception' => 'boom']);

    $result = doctorRun(new FailedJobsCheck);

    expect($result->status)->toBe(CheckStatus::Warn)
        ->and($result->message)->toContain('1 failed job');
});

// --- storage_permissions ---------------------------------------------------

test('Doctor storage_permissions passes on a normal checkout', function () {
    expect(doctorRun(new StoragePermissionsCheck)->status)->toBe(CheckStatus::Ok);
});

test('Doctor storage_permissions fails on an unwritable directory', function () {
    $directory = doctorTempDir();
    chmod($directory, 0500);

    $check = new class($directory) extends StoragePermissionsCheck
    {
        public function __construct(private readonly string $directory) {}

        protected function paths(): array
        {
            return [$this->directory, $this->directory.'/not-created-yet'];
        }
    };

    $result = doctorRun($check);

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->message)->toContain($directory);

    doctorRemoveDir($directory);
})->skip(fn () => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root can write anywhere');

// --- result_disk -----------------------------------------------------------

test('Doctor result_disk rejects public and unknown disks', function () {
    config(['queryproxy.result_disk' => 'local']);
    expect(doctorRun(new ResultDiskCheck)->status)->toBe(CheckStatus::Ok);

    config(['queryproxy.result_disk' => 'public']);
    expect(doctorRun(new ResultDiskCheck)->status)->toBe(CheckStatus::Fail);

    config(['queryproxy.result_disk' => 'exports', 'filesystems.disks.exports' => ['driver' => 'local', 'root' => '/tmp/x', 'visibility' => 'public']]);
    expect(doctorRun(new ResultDiskCheck)->status)->toBe(CheckStatus::Fail);

    config(['queryproxy.result_disk' => 'nope']);
    expect(doctorRun(new ResultDiskCheck)->message)->toContain('not a configured filesystem disk');
});

// --- sqlite_allowed_dir ----------------------------------------------------

test('Doctor sqlite_allowed_dir only worries when SQLite targets exist', function () {
    config(['queryproxy.sqlite_allowed_dir' => null]);
    expect(doctorRun(new SqliteAllowedDirCheck)->status)->toBe(CheckStatus::Ok);

    Connection::factory()->sqlite('/data/a.sqlite')->create();
    expect(doctorRun(new SqliteAllowedDirCheck)->status)->toBe(CheckStatus::Warn);

    $directory = doctorTempDir();
    config(['queryproxy.sqlite_allowed_dir' => $directory]);
    expect(doctorRun(new SqliteAllowedDirCheck)->status)->toBe(CheckStatus::Ok);

    config(['queryproxy.sqlite_allowed_dir' => $directory.'/missing']);
    expect(doctorRun(new SqliteAllowedDirCheck)->status)->toBe(CheckStatus::Warn);

    doctorRemoveDir($directory);
});

// --- tls_enforcement -------------------------------------------------------

test('Doctor tls_enforcement skips when nothing uses the network', function () {
    Connection::factory()->sqlite()->create();

    expect(doctorRun(new TlsEnforcementCheck)->status)->toBe(CheckStatus::Skip);
});

test('Doctor tls_enforcement passes when TLS is required everywhere', function () {
    Connection::factory()->create(['driver' => 'pgsql']);
    Connection::factory()->create(['driver' => 'mysql']);

    expect(doctorRun(new TlsEnforcementCheck)->status)->toBe(CheckStatus::Ok);
});

test('Doctor tls_enforcement flags sslmode=prefer and driver-default connections', function () {
    $prefer = Connection::factory()->create(['driver' => 'pgsql', 'name' => 'Legacy PG']);
    $prefer->forceFill(['options' => ['tls' => ['mode' => 'prefer']]])->save();

    $default = Connection::factory()->create(['driver' => 'mysql', 'name' => 'Legacy MySQL']);
    $default->forceFill(['options' => null])->save();

    $result = doctorRun(new TlsEnforcementCheck);

    expect($result->status)->toBe(CheckStatus::Warn)
        ->and($result->message)->toContain('1 not enforcing TLS')
        ->and($result->message)->toContain('Legacy PG [prefer]')
        ->and($result->message)->toContain('1 left to the driver default')
        ->and($result->message)->toContain('Legacy MySQL');
});

// --- tls_files -------------------------------------------------------------

test('Doctor tls_files passes without stored PEM material', function () {
    Connection::factory()->create();

    expect(doctorRun(new TlsFilesCheck)->status)->toBe(CheckStatus::Ok);
});

test('Doctor tls_files fails on a stored PEM that does not decrypt', function () {
    $connection = Connection::factory()->create();
    $connection->forceFill(['options' => ['tls' => ['mode' => 'verify-full', 'pem' => ['ca' => 'not-encrypted']]]])->save();

    $result = doctorRun(new TlsFilesCheck);

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->message)->toContain($connection->name);
});

test('Doctor tls_files passes for private files and warns on loose permissions', function () {
    $connection = Connection::factory()->create();
    $connection->setTlsPem('ca', "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----");
    $connection->save();

    $paths = app(TlsPemStore::class)->sync($connection);

    $ok = doctorRun(new TlsFilesCheck);
    expect($ok->status)->toBe(CheckStatus::Ok)
        ->and($ok->message)->toContain('1 stored PEM');

    chmod($paths['ca'], 0644);

    $warn = doctorRun(new TlsFilesCheck);
    expect($warn->status)->toBe(CheckStatus::Warn)
        ->and($warn->message)->toContain('0644');
});

test('Doctor tls_files warns about files left for a deleted connection', function () {
    mkdir(storage_path('app/tls/9999'), 0700, true);
    file_put_contents(storage_path('app/tls/9999/ca.pem'), 'x');
    chmod(storage_path('app/tls/9999/ca.pem'), 0600);

    $result = doctorRun(new TlsFilesCheck);

    expect($result->status)->toBe(CheckStatus::Warn)
        ->and($result->message)->toContain('deleted connections');
});

// --- masking_coverage ------------------------------------------------------

test('Doctor masking_coverage lists teams without an enabled rule', function () {
    expect(doctorRun(new MaskingCoverageCheck)->status)->toBe(CheckStatus::Skip);

    $bare = Team::factory()->create(['name' => 'Bare Team']);
    $bare->maskingRules()->delete();

    $covered = Team::factory()->create(['name' => 'Covered Team']);
    $covered->maskingRules()->delete();
    MaskingRule::factory()->create(['team_id' => $covered->id]);

    $disabled = Team::factory()->create(['name' => 'Disabled Team']);
    $disabled->maskingRules()->delete();
    MaskingRule::factory()->create(['team_id' => $disabled->id, 'enabled' => false]);

    $result = doctorRun(new MaskingCoverageCheck);

    expect($result->status)->toBe(CheckStatus::Warn)
        ->and($result->message)->toContain('Bare Team')
        ->and($result->message)->toContain('Disabled Team')
        ->and($result->message)->not->toContain('Covered Team');
});

test('Doctor masking_coverage passes when every team is covered', function () {
    $team = Team::factory()->create();
    $team->maskingRules()->delete();
    MaskingRule::factory()->create(['team_id' => $team->id]);

    expect(doctorRun(new MaskingCoverageCheck)->status)->toBe(CheckStatus::Ok);
});

// --- trusted_proxies -------------------------------------------------------

test('Doctor trusted_proxies fails on a wildcard', function (array $proxies, CheckStatus $expected) {
    config(['queryproxy.trusted_proxies' => $proxies]);

    expect(doctorRun(new TrustedProxiesCheck)->status)->toBe($expected);
})->with([
    'none' => [[], CheckStatus::Ok],
    'one proxy' => [['10.0.0.5'], CheckStatus::Ok],
    'cidr' => [['10.0.0.0/8'], CheckStatus::Ok],
    'star' => [['*'], CheckStatus::Fail],
    'star among others' => [['10.0.0.5', '*'], CheckStatus::Fail],
    'everything' => [['0.0.0.0/0'], CheckStatus::Fail],
    'everything v6' => [['::/0'], CheckStatus::Fail],
]);

// --- audit_chain -----------------------------------------------------------

test('Doctor audit_chain passes on an empty and on an intact chain', function () {
    expect(doctorRun(doctorAuditCheck())->status)->toBe(CheckStatus::Ok);

    foreach (range(1, 5) as $i) {
        audit()->record('test.event', metadata: ['i' => $i]);
    }

    expect(doctorRun(doctorAuditCheck())->status)->toBe(CheckStatus::Ok)
        ->and(doctorRun(doctorAuditCheck(full: true))->message)->toContain('5 records intact');
});

test('Doctor audit_chain finds a modified recent record', function () {
    audit()->record('test.one');
    $target = audit()->record('test.two');
    audit()->record('test.three');

    (new AuditTriggers)->drop();
    DB::table('audit_logs')->where('id', $target->id)->update(['sql' => 'DROP TABLE users']);

    $result = doctorRun(doctorAuditCheck());

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->message)->toContain("Record {$target->id}");
});

test('Doctor audit_chain finds a removed record and a replaced tail', function () {
    $first = audit()->record('test.one');
    $second = audit()->record('test.two');
    audit()->record('test.three');

    (new AuditTriggers)->drop();
    DB::table('audit_logs')->where('id', $second->id)->delete();

    expect(doctorRun(doctorAuditCheck())->status)->toBe(CheckStatus::Fail);

    // A removed tail leaves the lock row pointing at a head that is gone.
    DB::table('audit_logs')->where('id', '>', $first->id)->delete();

    $result = doctorRun(doctorAuditCheck());
    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->message)->toContain('were written');
});

test('Doctor audit_chain finds an emptied table', function () {
    audit()->record('test.one');

    (new AuditTriggers)->drop();
    DB::table('audit_logs')->delete();

    expect(doctorRun(doctorAuditCheck())->status)->toBe(CheckStatus::Fail);
});

test('Doctor audit_chain full mode walks the whole chain', function () {
    $target = audit()->record('test.one');
    audit()->record('test.two');

    (new AuditTriggers)->drop();
    DB::table('audit_logs')->where('id', $target->id)->update(['sql' => 'tampered']);

    $result = doctorRun(doctorAuditCheck(full: true));

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->message)->toContain("record id {$target->id}");
});

test('Doctor audit_chain quick mode looks only at the newest window', function () {
    $old = audit()->record('test.old');

    foreach (range(1, AuditChainCheck::WINDOW + 5) as $i) {
        audit()->record('test.filler');
    }

    (new AuditTriggers)->drop();
    DB::table('audit_logs')->where('id', $old->id)->update(['sql' => 'tampered']);

    expect(doctorRun(doctorAuditCheck())->status)->toBe(CheckStatus::Ok)
        ->and(doctorRun(doctorAuditCheck(full: true))->status)->toBe(CheckStatus::Fail);
});

test('Doctor audit_chain notices when the oldest record of a short chain was removed', function () {
    $first = audit()->record('test.one');
    audit()->record('test.two');
    audit()->record('test.three');

    (new AuditTriggers)->drop();
    DB::table('audit_logs')->where('id', $first->id)->delete();

    $result = doctorRun(doctorAuditCheck());

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->message)->toContain('does not start the chain')
        ->and(doctorRun(doctorAuditCheck(full: true))->status)->toBe(CheckStatus::Fail);
});

test('Doctor audit_chain does not report a record that was committed between its two reads', function () {
    audit()->record('test.one');
    audit()->record('test.two');

    // The lock row is read after the records; a writer commits in between, the
    // first lock read is therefore stale. The next attempt sees a settled chain.
    $check = new class(new AuditChain, app(AuditVerifier::class)) extends AuditChainCheck
    {
        public int $reads = 0;

        protected function readLock(): ?object
        {
            $lock = parent::readLock();

            if (++$this->reads === 1) {
                $lock->sequence = (int) $lock->sequence + 1;
                $lock->head_hash = str_repeat('0', 64);
            }

            return $lock;
        }
    };

    expect($check->run()->status)->toBe(CheckStatus::Ok)
        ->and($check->reads)->toBe(2);
});

test('Doctor audit_chain still fails once the retries are used up', function () {
    audit()->record('test.one');

    $check = new class(new AuditChain, app(AuditVerifier::class)) extends AuditChainCheck
    {
        public int $reads = 0;

        protected function readLock(): ?object
        {
            $this->reads++;
            $lock = parent::readLock();
            $lock->head_hash = str_repeat('0', 64);

            return $lock;
        }
    };

    expect($check->run()->status)->toBe(CheckStatus::Fail)
        ->and($check->reads)->toBe(AuditChainCheck::ATTEMPTS);
});

// --- siem_sinks ------------------------------------------------------------

test('Doctor siem_sinks validates the sinks that are turned on', function () {
    config(['queryproxy.siem.sinks' => []]);
    expect(doctorRun(new SiemCheck)->status)->toBe(CheckStatus::Skip);

    config(['queryproxy.siem.sinks' => ['stdout']]);
    expect(doctorRun(new SiemCheck)->status)->toBe(CheckStatus::Ok);

    config(['queryproxy.siem.sinks' => ['webhook'], 'queryproxy.siem.webhook' => ['url' => 'https://siem.example.com/in', 'secret' => 'short']]);
    expect(doctorRun(new SiemCheck)->status)->toBe(CheckStatus::Fail);

    config(['queryproxy.siem.webhook.secret' => str_repeat('s', 20)]);
    expect(doctorRun(new SiemCheck)->status)->toBe(CheckStatus::Ok);

    config(['queryproxy.siem.webhook.url' => 'http://siem.example.com/in']);
    expect(doctorRun(new SiemCheck)->status)->toBe(CheckStatus::Warn);

    config(['queryproxy.siem.sinks' => ['carrier-pigeon']]);
    expect(doctorRun(new SiemCheck)->status)->toBe(CheckStatus::Fail);

    config(['queryproxy.siem.sinks' => ['syslog'], 'queryproxy.siem.syslog.host' => '']);
    expect(doctorRun(new SiemCheck)->status)->toBe(CheckStatus::Fail);

    config(['queryproxy.siem.syslog.host' => 'logs.example.com', 'queryproxy.siem.syslog.transport' => 'tls', 'queryproxy.siem.syslog.tls_ca_file' => '/nonexistent/ca.pem']);
    expect(doctorRun(new SiemCheck)->status)->toBe(CheckStatus::Fail);
});

// --- metrics ---------------------------------------------------------------

test('Doctor metrics looks at the token', function () {
    expect(doctorRun(new MetricsCheck)->status)->toBe(CheckStatus::Skip);

    putenv('QUERYPROXY_METRICS_TOKEN=short');
    expect(doctorRun(new MetricsCheck)->status)->toBe(CheckStatus::Warn);

    putenv('QUERYPROXY_METRICS_TOKEN='.str_repeat('t', 32));
    expect(doctorRun(new MetricsCheck)->status)->toBe(CheckStatus::Ok);
});

test('Doctor metrics accepts an IP allowlist without a token', function () {
    config(['queryproxy.metrics.allowed_ips' => ['10.0.0.0/8']]);

    expect(doctorRun(new MetricsCheck)->status)->toBe(CheckStatus::Ok);
});

test('Doctor metrics warns about allowlist entries that can never match', function (string $entry) {
    config(['queryproxy.metrics.allowed_ips' => ['10.0.0.0/8', $entry]]);

    expect(doctorRun(new MetricsCheck)->status)->toBe(CheckStatus::Warn);
})->with(['prometheus.local', '10.0.0.0/33', '2001:db8::/129', '10.0.0.1/', '10.0.0.0/x']);

test('Doctor metrics reports a bad allowlist entry and a short token together', function () {
    putenv('QUERYPROXY_METRICS_TOKEN=short');
    config(['queryproxy.metrics.allowed_ips' => ['prometheus.local']]);

    $result = doctorRun(new MetricsCheck);

    expect($result->status)->toBe(CheckStatus::Warn)
        ->and($result->message)->toContain('prometheus.local')
        ->and($result->message)->toContain('shorter than');
});

test('Doctor metrics accepts addresses and ranges of both families', function () {
    config(['queryproxy.metrics.allowed_ips' => ['192.0.2.7', '10.1.0.0/16', '2001:db8::5', '2001:db8::/32']]);

    expect(doctorRun(new MetricsCheck)->status)->toBe(CheckStatus::Ok);
});

test('Doctor metrics reports an unusable QUERYPROXY_METRICS_TOKEN_FILE', function () {
    putenv('QUERYPROXY_METRICS_TOKEN_FILE=/nonexistent/token');

    expect(doctorRun(new MetricsCheck)->status)->toBe(CheckStatus::Fail);
});

// --- runner ----------------------------------------------------------------

test('Doctor survives a check that throws', function () {
    $broken = new class implements Check
    {
        public function name(): string
        {
            return 'broken';
        }

        public function run(): CheckResult
        {
            throw new RuntimeException("boom\nsecond line");
        }
    };

    $results = (new Doctor([$broken, new TrustedProxiesCheck]))->run();

    expect($results)->toHaveCount(2)
        ->and($results[0]->status)->toBe(CheckStatus::Fail)
        ->and($results[0]->message)->toBe('The check could not run: boom')
        ->and($results[1]->status)->toBe(CheckStatus::Ok);
});

test('Doctor skips database checks when the database is unreachable', function () {
    config([
        'database.default' => 'doctor_broken',
        'database.connections.doctor_broken' => ['driver' => 'sqlite', 'database' => '/nonexistent/dir/db.sqlite', 'prefix' => ''],
    ]);

    $byName = collect(Doctor::default()->run())->keyBy('check');

    expect($byName['database']->status)->toBe(CheckStatus::Fail)
        ->and($byName['masking_coverage']->status)->toBe(CheckStatus::Skip)
        ->and($byName['audit_chain']->status)->toBe(CheckStatus::Skip)
        ->and($byName['trusted_proxies']->status)->toBe(CheckStatus::Ok);
});

<?php

/*
 * Connection TLS on real servers (Faz 04, ADR-019): "required" has to mean
 * that the connection fails instead of falling back to plaintext.
 *
 *  - Positive: against a server that offers TLS, a "required" connection
 *    works and the server itself reports the session as encrypted
 *    (pg_stat_ssl, Ssl_cipher, sys.dm_exec_connections), and the executor
 *    runs a request over it. With a CA certificate stored, verification
 *    succeeds; without one it is refused.
 *  - Negative: against a server that does not offer TLS, a "required"
 *    connection fails, and fails the same way with a wrong password, so the
 *    credentials never reached the server's authentication (an
 *    "access denied" would mean they had). Where the server can be reached
 *    without TLS at all, the same login is shown to work in that mode, so the
 *    failure is TLS and nothing else.
 *  - SQL Server always offers TLS (a self-signed certificate it makes itself;
 *    there is no switch to turn that off), so its negative is the untrusted
 *    certificate: Encrypt=true with TrustServerCertificate=false fails,
 *    TrustServerCertificate=true connects. The same pair is run against ODBC
 *    Driver 18 and 17 directly through pdo_sqlsrv, and the refusal is shown to
 *    come from the TLS handshake (a wrong password gives the same certificate
 *    error, never "login failed"). What this cannot show is a server that
 *    refuses TLS altogether: that downgrade case rests on the driver's
 *    documented behaviour (Encrypt=true ends the connection when the server
 *    does not encrypt), not on a test.
 *
 * Which server is which comes from QUERYPROXY_IT_TLS_ON_PORT /
 * QUERYPROXY_IT_TLS_OFF_PORT and QUERYPROXY_IT_TLS_CA (see
 * docker-compose.integration.yml). Group "integration".
 */

use App\Enums\DbDriver;
use App\Enums\QueryRequestStatus;
use App\Models\Connection;
use App\Services\Connections\DynamicConnectionFactory;
use Tests\Integration\IntegrationTarget;

beforeEach(function () {
    if (! IntegrationTarget::configured()) {
        IntegrationTarget::failIfRequired('the server is not configured.');
        $this->markTestSkipped(IntegrationTarget::SKIP_MESSAGE);
    }

    $this->it = IntegrationTarget::start();
});

afterEach(function () {
    if (isset($this->it)) {
        $this->it->stop();
    }
});

/** The "required TLS" settings for the driver; $verify turns certificate verification on. */
function itRequiredTls(bool $verify = false): array
{
    return match (IntegrationTarget::driver()) {
        DbDriver::Pgsql => ['mode' => $verify ? 'verify-full' : 'require'],
        DbDriver::Sqlsrv => ['mode' => Connection::TLS_MODE_REQUIRED, 'trust_server_certificate' => ! $verify],
        default => ['mode' => Connection::TLS_MODE_REQUIRED, 'verify_server_cert' => $verify],
    };
}

/** Settings that ask for no TLS at all, for the control connection. */
function itPlainTls(): array
{
    return IntegrationTarget::driver() === DbDriver::Pgsql ? ['mode' => 'disable'] : ['mode' => 'disabled'];
}

/** Port of the server that offers (ON) or does not offer (OFF) TLS, or null when none is configured. */
function itTlsPort(string $which): ?string
{
    $port = IntegrationTarget::env('TLS_'.$which.'_PORT');

    return $port === '' ? null : $port;
}

/** Message of the skip for a missing TLS server. */
function itNoTlsServer(string $which): string
{
    return "QUERYPROXY_IT_TLS_{$which}_PORT is not set: there is no server of this driver to test against.";
}

test('required TLS connects to a server that offers TLS, the server reports the session encrypted and the executor runs over it', function () {
    $port = itTlsPort('ON');

    if ($port === null) {
        IntegrationTarget::failIfRequired(itNoTlsServer('ON'));
        $this->markTestSkipped(itNoTlsServer('ON'));
    }

    // Precondition: this really is a server that offers TLS.
    if (IntegrationTarget::driver() !== DbDriver::Sqlsrv) {
        expect(IntegrationTarget::offersTls($port))->toBeTrue('the server on port '.$port.' was meant to offer TLS but does not: a test set-up problem, not an application one');
    }

    $connection = $this->it->connection(['port' => $port], itRequiredTls());

    $result = app(DynamicConnectionFactory::class)->test($connection);

    expect($result['ok'])->toBeTrue($result['error'] ?? '')
        ->and($result['tls']['state'])->toBe(Connection::TLS_ENFORCED)
        ->and($result['tls']['encrypted'])->toBeTrue();

    $request = $this->it->execute($this->it->request($connection, 'SELECT 1 AS one'));

    expect($request->status)->toBe(QueryRequestStatus::Completed, (string) $request->error_message)
        ->and($this->it->resultRows($request))->toBe([['1']]);
});

test('certificate verification accepts the CA that signed the server certificate and refuses a missing one', function () {
    if (IntegrationTarget::driver() === DbDriver::Sqlsrv) {
        $this->markTestSkipped('SQL Server\'s certificate is generated by the server itself, there is no CA to give it (see the TrustServerCertificate tests).');
    }

    $port = itTlsPort('ON');

    if ($port === null) {
        IntegrationTarget::failIfRequired(itNoTlsServer('ON'));
        $this->markTestSkipped(itNoTlsServer('ON'));
    }

    $ca = IntegrationTarget::caPem();
    $factory = app(DynamicConnectionFactory::class);

    // Without a CA the self-made certificate cannot be verified.
    $withoutCa = $factory->test($this->it->connection(['port' => $port], itRequiredTls(verify: true)));

    expect($withoutCa['ok'])->toBeFalse();

    // MySQL makes its own certificate; the CA file belongs to the other servers.
    if (IntegrationTarget::driver() === DbDriver::Mysql) {
        return;
    }

    if ($ca === null) {
        IntegrationTarget::failIfRequired('QUERYPROXY_IT_TLS_CA is not set or not readable.');
        $this->markTestSkipped('QUERYPROXY_IT_TLS_CA is not set or not readable.');
    }

    $connection = $this->it->connection(['port' => $port], itRequiredTls(verify: true));
    $connection->setTlsPem('ca', $ca);
    $connection->save();

    $result = $factory->test($connection->fresh());

    expect($result['ok'])->toBeTrue($result['error'] ?? '')
        ->and($result['tls']['encrypted'])->toBeTrue();
});

test('required TLS fails against a server without TLS before the credentials are evaluated', function () {
    if (IntegrationTarget::driver() === DbDriver::Sqlsrv) {
        $this->markTestSkipped('SQL Server always offers TLS; its negative is the untrusted certificate test below.');
    }

    $port = itTlsPort('OFF');

    if ($port === null) {
        IntegrationTarget::failIfRequired(itNoTlsServer('OFF'));
        $this->markTestSkipped(itNoTlsServer('OFF'));
    }

    $factory = app(DynamicConnectionFactory::class);

    expect(IntegrationTarget::reachable($port))->toBeTrue('the server without TLS is not reachable on port '.$port);

    // Precondition, read from the server's own first bytes: it really does not
    // offer TLS. Without this a server that does (a new default, an ignored
    // start-up flag) would make the test below fail like an application bug.
    expect(IntegrationTarget::offersTls($port))->toBeFalse('the server on port '.$port.' was meant to offer no TLS but does: a test set-up problem, not an application one');

    // Where the login works without TLS, show it, so the failure below is TLS and nothing else.
    // (MySQL 8.4 accounts use caching_sha2_password, whose first login needs TLS or an RSA key.)
    if (IntegrationTarget::driver() !== DbDriver::Mysql) {
        $control = $factory->test($this->it->connection(['port' => $port], itPlainTls()));

        expect($control['ok'])->toBeTrue($control['error'] ?? '')
            ->and($control['tls']['encrypted'])->toBeFalse();
    }

    $required = $this->it->connection(['port' => $port], itRequiredTls());
    $result = $factory->test($required);

    expect($result['ok'])->toBeFalse()
        ->and($result['tls']['state'])->toBe(Connection::TLS_ENFORCED);

    // With a wrong password the failure is the same, not "access denied": the
    // server never got to look at the credentials.
    $required->update(['password' => 'definitely-not-the-password']);
    $wrong = $factory->test($required->fresh());

    expect($wrong['ok'])->toBeFalse()
        ->and(strtolower((string) $wrong['error']))->not->toMatch('/access denied|authentication failed|password/');
});

test('SQL Server: Encrypt=true with TrustServerCertificate=false refuses a self-signed certificate, TrustServerCertificate=true connects encrypted', function () {
    if (IntegrationTarget::driver() !== DbDriver::Sqlsrv) {
        $this->markTestSkipped('SQL Server only.');
    }

    $factory = app(DynamicConnectionFactory::class);

    $untrusted = $factory->test($this->it->connection([], itRequiredTls(verify: true)));

    expect($untrusted['ok'])->toBeFalse()
        ->and($untrusted['tls']['state'])->toBe(Connection::TLS_ENFORCED)
        ->and(strtolower((string) $untrusted['error']))->toMatch('/certificate|ssl|tls|trust/');

    // The refusal comes from the TLS handshake, before authentication: with a
    // wrong password it is the same certificate error, not "login failed".
    $wrongPassword = $this->it->connection([], itRequiredTls(verify: true));
    $wrongPassword->update(['password' => 'definitely-not-the-password']);
    $wrong = $factory->test($wrongPassword->fresh());

    expect($wrong['ok'])->toBeFalse()
        ->and(strtolower((string) $wrong['error']))->toMatch('/certificate|ssl|tls|trust/')
        ->and(strtolower((string) $wrong['error']))->not->toMatch('/login failed|authentication/');

    $trusted = $factory->test($this->it->connection([], itRequiredTls()));

    expect($trusted['ok'])->toBeTrue($trusted['error'] ?? '')
        ->and($trusted['tls']['encrypted'])->toBeTrue();
});

test('SQL Server: pdo_sqlsrv accepts Encrypt and TrustServerCertificate on ODBC Driver 18 and 17', function (string $odbcDriver) {
    if (IntegrationTarget::driver() !== DbDriver::Sqlsrv) {
        $this->markTestSkipped('SQL Server only.');
    }

    $installed = (string) @shell_exec('odbcinst -q -d 2>/dev/null');

    if (! str_contains($installed, "[{$odbcDriver}]")) {
        $this->markTestSkipped("{$odbcDriver} is not installed on this machine.");
    }

    $dsn = fn (bool $trust): string => sprintf(
        'sqlsrv:Server=%s,%s;Database=%s;Driver=%s;Encrypt=true;TrustServerCertificate=%s',
        IntegrationTarget::env('HOST'),
        IntegrationTarget::env('PORT'),
        IntegrationTarget::env('DATABASE', 'queryproxy_test'),
        $odbcDriver,
        $trust ? 'true' : 'false',
    );

    $connect = fn (bool $trust, ?string $password = null): PDO => new PDO($dsn($trust), IntegrationTarget::env('USERNAME'), $password ?? IntegrationTarget::env('PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // Encrypt=true is understood and enforced: the untrusted certificate stops
    // the login, with the right password and with a wrong one alike (the
    // handshake fails before authentication, so the error is not "login failed").
    foreach ([null, 'definitely-not-the-password'] as $password) {
        $refused = null;

        try {
            $connect(false, $password);
        } catch (PDOException $e) {
            $refused = $e;
        }

        expect($refused)->toBeInstanceOf(PDOException::class)
            ->and(strtolower((string) $refused?->getMessage()))->toMatch('/certificate|ssl|tls|trust/')
            ->and(strtolower((string) $refused?->getMessage()))->not->toMatch('/login failed/');
    }

    $pdo = $connect(true);

    expect(strtoupper((string) $pdo->query('SELECT encrypt_option FROM sys.dm_exec_connections WHERE session_id = @@SPID')->fetchColumn()))->toBe('TRUE');
})->with([
    'ODBC Driver 18' => ['ODBC Driver 18 for SQL Server'],
    'ODBC Driver 17' => ['ODBC Driver 17 for SQL Server'],
]);

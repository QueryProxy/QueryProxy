<?php

namespace App\Livewire\Connections;

use App\Enums\DbDriver;
use App\Enums\TeamRole;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Services\Connections\DynamicConnectionFactory;
use App\Services\Connections\TlsPemStore;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Connections')]
class Index extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    public ?int $grantsForId = null;

    public string $name = '';

    public string $driver = 'pgsql';

    public string $host = '';

    public string $port = '';

    public string $database = '';

    public string $username = '';

    public string $password = '';

    public string $readUsername = '';

    public string $readPassword = '';

    /**
     * TLS section. PostgreSQL: disable | prefer | require | verify-ca | verify-full.
     * MySQL/MariaDB/SQL Server: required | default (left to the driver).
     */
    public string $tlsMode = 'require';

    public bool $tlsVerifyServerCert = false;

    public bool $tlsTrustServerCertificate = false;

    /** PEM text typed into the form. Never filled from storage: a blank keeps the stored PEM. */
    public string $tlsCa = '';

    public string $tlsCert = '';

    public string $tlsKey = '';

    public bool $tlsClearCa = false;

    public bool $tlsClearCert = false;

    public bool $tlsClearKey = false;

    /** @var array{ok: bool, latency_ms: float|null, error: string|null, tls: array{state: string, mode: string|null, encrypted: bool|null}}|null */
    public ?array $testResult = null;

    public ?int $testedId = null;

    private function team(): Team
    {
        return auth()->user()->currentTeam() ?? abort(403);
    }

    public function openCreate(): void
    {
        $this->authorize('create', Connection::class);
        $this->resetForm();
        $this->showForm = true;
    }

    public function openEdit(int $connectionId): void
    {
        $connection = Connection::forTeam($this->team())->findOrFail($connectionId);
        $this->authorize('update', $connection);

        $this->resetForm();
        $this->editingId = $connection->id;
        $this->name = $connection->name;
        $this->driver = $connection->driver->value;
        $this->host = (string) $connection->host;
        $this->port = (string) $connection->port;
        $this->database = (string) $connection->database;
        $this->username = (string) $connection->username;
        $this->password = '';
        $this->readUsername = (string) $connection->read_username;
        $this->readPassword = '';
        $this->tlsMode = $connection->driver === DbDriver::Pgsql
            ? (string) $connection->tlsMode()
            : ($connection->tlsMode() === Connection::TLS_MODE_REQUIRED ? Connection::TLS_MODE_REQUIRED : 'default');
        $this->tlsVerifyServerCert = $connection->tlsFlag('verify_server_cert');
        $this->tlsTrustServerCertificate = $connection->tlsFlag('trust_server_certificate');
        $this->showForm = true;
    }

    /** Switching the driver resets the TLS mode to that driver's required-TLS default. */
    public function updatedDriver(): void
    {
        $this->tlsMode = $this->defaultTlsMode($this->driver);
    }

    private function defaultTlsMode(string $driver): string
    {
        return $driver === DbDriver::Pgsql->value ? 'require' : Connection::TLS_MODE_REQUIRED;
    }

    public function save(DynamicConnectionFactory $factory, TlsPemStore $pemStore): void
    {
        $team = $this->team();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'driver' => ['required', Rule::enum(DbDriver::class)],
            'host' => ['nullable', 'string', 'max:255', 'required_unless:driver,sqlite'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'database' => ['required', 'string', 'max:1024'],
            'username' => ['nullable', 'string', 'max:255', 'required_unless:driver,sqlite'],
            'password' => ['nullable', 'string', 'max:1024'],
            'readUsername' => ['nullable', 'string', 'max:255'],
            'readPassword' => ['nullable', 'string', 'max:1024'],
            'tlsMode' => $this->driver === DbDriver::Sqlite->value
                ? ['nullable', 'string']
                : ['required', 'string', Rule::in($this->tlsModesFor($this->driver))],
            'tlsCa' => ['nullable', 'string', 'max:65536', $this->pemRule('certificate')],
            'tlsCert' => ['nullable', 'string', 'max:65536', $this->pemRule('certificate')],
            'tlsKey' => ['nullable', 'string', 'max:65536', $this->pemRule('key')],
        ]);

        $existing = $this->editingId ? Connection::forTeam($team)->findOrFail($this->editingId) : null;

        if (! $this->validateTlsCombination($validated['driver'], $existing)) {
            return;
        }

        $attributes = [
            'name' => $validated['name'],
            'driver' => $validated['driver'],
            'host' => $validated['host'] ?: null,
            'port' => $validated['port'] ?: null,
            'database' => $validated['database'],
            'username' => $validated['username'] ?: null,
        ];

        // The read-only identity is optional and meaningless for SQLite
        // (a file has no users); a blank username drops the whole identity.
        $keepReadIdentity = $validated['driver'] !== DbDriver::Sqlite->value && trim($this->readUsername) !== '';

        if ($keepReadIdentity) {
            $attributes['read_username'] = $this->readUsername;
        } else {
            $attributes['read_username'] = null;
            $attributes['read_password'] = null;
        }

        $violations = $factory->targetViolations(new Connection($attributes));

        if ($violations !== []) {
            $this->addError('database', $violations[0]);

            return;
        }

        if ($this->editingId) {
            $connection = Connection::forTeam($team)->findOrFail($this->editingId);
            $this->authorize('update', $connection);

            // Blank password means "keep the stored one".
            if ($this->password !== '') {
                $attributes['password'] = $this->password;
            }

            if ($keepReadIdentity && $this->readPassword !== '') {
                $attributes['read_password'] = $this->readPassword;
            }

            $connection->fill($attributes);
            $this->applyTls($connection);
            $connection->save();
            $pemStore->reconcile($connection);
            audit()->record('connection.updated', connection: $connection, metadata: ['name' => $connection->name]);
        } else {
            $this->authorize('create', Connection::class);

            $connection = new Connection($attributes + [
                'team_id' => $team->id,
                'password' => $this->password !== '' ? $this->password : null,
                'read_password' => $keepReadIdentity && $this->readPassword !== '' ? $this->readPassword : null,
                'created_by' => auth()->id(),
            ]);
            $this->applyTls($connection);
            $connection->save();
            $pemStore->reconcile($connection);
            audit()->record('connection.created', connection: $connection, metadata: ['name' => $connection->name]);
        }

        $this->resetForm();
        $this->showForm = false;
    }

    /** @return list<string> */
    private function tlsModesFor(string $driver): array
    {
        return $driver === DbDriver::Pgsql->value
            ? Connection::TLS_PGSQL_MODES
            : [Connection::TLS_MODE_REQUIRED, 'default'];
    }

    /** @return \Closure(string, mixed, \Closure): void */
    private function pemRule(string $type): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($type) {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            // ext-openssl raises a PHP warning on malformed input; Laravel would turn it into an exception.
            set_error_handler(static fn (): bool => true);

            try {
                $valid = $type === 'key'
                    ? openssl_pkey_get_private($value) !== false
                    : openssl_x509_read($value) !== false;
            } finally {
                restore_error_handler();
            }

            if (! $valid) {
                $fail($type === 'key'
                    ? 'Paste a valid PEM private key that is not passphrase-protected.'
                    : 'Paste a valid PEM certificate.');
            }
        };
    }

    /** Cross-field TLS rules; returns false (with errors added) when the form must not be saved. */
    private function validateTlsCombination(string $driver, ?Connection $existing): bool
    {
        if ($driver === DbDriver::Sqlite->value || $driver === DbDriver::Sqlsrv->value) {
            return true;
        }

        $has = fn (string $kind, string $input, bool $clear): bool => trim($input) !== ''
            || (! $clear && $existing?->hasTlsPem($kind) === true);

        $hasCa = $has('ca', $this->tlsCa, $this->tlsClearCa);
        $hasCert = $has('cert', $this->tlsCert, $this->tlsClearCert);
        $hasKey = $has('key', $this->tlsKey, $this->tlsClearKey);
        $ok = true;

        if ($hasCert !== $hasKey) {
            $this->addError($hasCert ? 'tlsKey' : 'tlsCert', 'The client certificate and its private key must be set together.');
            $ok = false;
        }

        if ($driver === DbDriver::Pgsql->value && in_array($this->tlsMode, ['verify-ca', 'verify-full'], true) && ! $hasCa) {
            $this->addError('tlsCa', 'This sslmode verifies the server, so a CA certificate is required.');
            $ok = false;
        }

        return $ok;
    }

    /** Write the form's TLS settings (and PEM changes) onto the model; the caller saves it. */
    private function applyTls(Connection $connection): void
    {
        $driver = $connection->driver;

        if ($driver === DbDriver::Sqlite) {
            $options = $connection->options ?? [];
            unset($options['tls']);
            $connection->options = $options === [] ? null : $options;

            return;
        }

        $tls = ['mode' => $driver === DbDriver::Pgsql
            ? $this->tlsMode
            : ($this->tlsMode === Connection::TLS_MODE_REQUIRED ? Connection::TLS_MODE_REQUIRED : null)];

        if ($driver === DbDriver::Mysql || $driver === DbDriver::Mariadb) {
            $tls['verify_server_cert'] = $this->tlsVerifyServerCert;
        }

        if ($driver === DbDriver::Sqlsrv) {
            $tls['trust_server_certificate'] = $this->tlsTrustServerCertificate;
        }

        if (isset($connection->tlsSettings()['pem'])) {
            $tls['pem'] = $connection->tlsSettings()['pem'];
        }

        $connection->options = array_merge($connection->options ?? [], ['tls' => $tls]);

        // SQL Server has no certificate-file options (it uses the system trust store).
        $kinds = $driver === DbDriver::Sqlsrv
            ? ['ca' => [null, true], 'cert' => [null, true], 'key' => [null, true]]
            : [
                'ca' => [$this->tlsCa, $this->tlsClearCa],
                'cert' => [$this->tlsCert, $this->tlsClearCert],
                'key' => [$this->tlsKey, $this->tlsClearKey],
            ];

        foreach ($kinds as $kind => [$input, $clear]) {
            if ($clear) {
                $connection->setTlsPem($kind, null);
            } elseif ($input !== null && trim($input) !== '') {
                $connection->setTlsPem($kind, $input);
            }
        }

        // Record why a non-enforcing connection is flagged (same shape as the migration).
        $options = $connection->options;

        if ($connection->tlsState() === Connection::TLS_ENFORCED) {
            unset($options['tls']['flag']);
        } else {
            $options['tls']['flag'] = $connection->tlsState();
        }

        $connection->options = $options;
    }

    public function deleteConnection(int $connectionId): void
    {
        $connection = Connection::forTeam($this->team())->findOrFail($connectionId);
        $this->authorize('delete', $connection);

        audit()->record('connection.deleted', connection: $connection, metadata: ['name' => $connection->name]);

        // The DB cascade removes the query_requests rows without firing model
        // events, so stored result files must be deleted explicitly.
        QueryRequest::where('connection_id', $connection->id)
            ->whereNotNull('result_path')
            ->lazyById()
            ->each(function (QueryRequest $request) {
                Storage::disk($request->result_disk ?? config('queryproxy.result_disk', 'local'))
                    ->delete($request->result_path);
            });

        $connection->delete();
    }

    public function testConnection(int $connectionId, DynamicConnectionFactory $factory): void
    {
        $connection = Connection::forTeam($this->team())->findOrFail($connectionId);
        $this->authorize('update', $connection);

        $this->testResult = $factory->test($connection);
        $this->testedId = $connection->id;

        audit()->record('connection.tested', connection: $connection, metadata: [
            'ok' => $this->testResult['ok'],
            'error' => $this->testResult['error'],
        ]);
    }

    public function toggleGrants(int $connectionId): void
    {
        $this->grantsForId = $this->grantsForId === $connectionId ? null : $connectionId;
    }

    public function toggleGrant(int $connectionId, int $userId): void
    {
        $team = $this->team();
        $connection = Connection::forTeam($team)->findOrFail($connectionId);
        $this->authorize('update', $connection);

        $developer = $team->users()->wherePivot('role', TeamRole::Developer->value)->findOrFail($userId);

        if ($connection->isGrantedTo($developer)) {
            $connection->grantedUsers()->detach($developer->id);
            audit()->record('connection.grant_revoked', connection: $connection, metadata: ['developer' => $developer->email]);
        } else {
            $connection->grantedUsers()->attach($developer->id);
            audit()->record('connection.grant_added', connection: $connection, metadata: ['developer' => $developer->email]);
        }
    }

    private function resetForm(): void
    {
        $this->reset(
            'editingId', 'name', 'host', 'port', 'database', 'username', 'password', 'readUsername', 'readPassword',
            'tlsVerifyServerCert', 'tlsTrustServerCertificate', 'tlsCa', 'tlsCert', 'tlsKey', 'tlsClearCa', 'tlsClearCert', 'tlsClearKey',
        );
        $this->driver = 'pgsql';
        $this->tlsMode = $this->defaultTlsMode($this->driver);
        $this->resetErrorBag();
    }

    public function render()
    {
        $team = $this->team();
        $connections = Connection::forTeam($team)->with('grantedUsers')->orderBy('name')->get();
        $editing = $this->editingId ? $connections->firstWhere('id', $this->editingId) : null;

        return view('livewire.connections.index', [
            'connections' => $connections,
            'developers' => $team->users()->wherePivot('role', TeamRole::Developer->value)->orderBy('name')->get(),
            'drivers' => DbDriver::cases(),
            // Which PEMs are already stored for the connection being edited
            // (the PEM text itself is never sent back to the browser).
            'storedPem' => collect(Connection::TLS_PEM_KINDS)
                ->mapWithKeys(fn (string $kind) => [$kind => $editing?->hasTlsPem($kind) ?? false])
                ->all(),
            // Without QUERYPROXY_SQLITE_ALLOWED_DIR a SQLite connection may
            // point at any file the app can read; the form says so, but does
            // not refuse the save.
            'sqliteDirUnrestricted' => blank(config('queryproxy.sqlite_allowed_dir')),
        ]);
    }
}

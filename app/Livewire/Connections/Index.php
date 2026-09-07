<?php

namespace App\Livewire\Connections;

use App\Enums\DbDriver;
use App\Enums\TeamRole;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Services\Connections\DynamicConnectionFactory;
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

    /** @var array{ok: bool, latency_ms: float|null, error: string|null}|null */
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
        $this->showForm = true;
    }

    public function save(DynamicConnectionFactory $factory): void
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
        ]);

        $attributes = [
            'name' => $validated['name'],
            'driver' => $validated['driver'],
            'host' => $validated['host'] ?: null,
            'port' => $validated['port'] ?: null,
            'database' => $validated['database'],
            'username' => $validated['username'] ?: null,
        ];

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

            $connection->update($attributes);
            audit()->record('connection.updated', connection: $connection, metadata: ['name' => $connection->name]);
        } else {
            $this->authorize('create', Connection::class);

            $connection = Connection::create($attributes + [
                'team_id' => $team->id,
                'password' => $this->password !== '' ? $this->password : null,
                'created_by' => auth()->id(),
            ]);
            audit()->record('connection.created', connection: $connection, metadata: ['name' => $connection->name]);
        }

        $this->resetForm();
        $this->showForm = false;
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
        $this->reset('editingId', 'name', 'host', 'port', 'database', 'username', 'password');
        $this->driver = 'pgsql';
        $this->resetErrorBag();
    }

    public function render()
    {
        $team = $this->team();

        return view('livewire.connections.index', [
            'connections' => Connection::forTeam($team)->with('grantedUsers')->orderBy('name')->get(),
            'developers' => $team->users()->wherePivot('role', TeamRole::Developer->value)->orderBy('name')->get(),
            'drivers' => DbDriver::cases(),
        ]);
    }
}

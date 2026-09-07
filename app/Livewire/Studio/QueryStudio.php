<?php

namespace App\Livewire\Studio;

use App\Enums\QueryRequestStatus;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Services\Approvals\ApprovalNotifier;
use App\Services\Sql\SqlInspector;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Query Studio')]
class QueryStudio extends Component
{
    public ?int $connectionId = null;

    public string $title = '';

    public string $sql = '';

    /** @var list<string> */
    public array $violations = [];

    public ?string $preparedPreview = null;

    public ?string $previewType = null;

    public bool $validated = false;

    private function team(): Team
    {
        return auth()->user()->currentTeam() ?? abort(403);
    }

    /** Connections the current user may submit queries through. */
    private function usableConnections()
    {
        $user = auth()->user();

        return Connection::forTeam($this->team())
            ->orderBy('name')
            ->get()
            ->filter(fn (Connection $c) => $user->can('use', $c))
            ->values();
    }

    public function updatedSql(): void
    {
        $this->validated = false;
        $this->violations = [];
        $this->preparedPreview = null;
    }

    public function validateSql(SqlInspector $inspector): bool
    {
        $this->validate([
            'sql' => ['required', 'string', 'max:65535'],
        ]);

        $driver = $this->connectionId
            ? Connection::forTeam($this->team())->find($this->connectionId)?->driver
            : null;

        $result = $inspector->inspect($this->sql, $driver);

        $this->violations = $result->violations;
        $this->preparedPreview = $result->passes() ? $result->preparedSql() : null;
        $this->previewType = $result->passes() ? $result->type()->value : null;
        $this->validated = $result->passes();

        return $result->passes();
    }

    public function submit(SqlInspector $inspector): void
    {
        $this->validate([
            'connectionId' => ['required', 'integer'],
            'title' => ['nullable', 'string', 'max:150'],
            'sql' => ['required', 'string', 'max:65535'],
        ]);

        $connection = Connection::forTeam($this->team())->findOrFail($this->connectionId);
        $this->authorize('use', $connection);

        if (! $this->validateSql($inspector)) {
            return;
        }

        $result = $inspector->inspect($this->sql, $connection->driver);

        $request = QueryRequest::create([
            'team_id' => $this->team()->id,
            'connection_id' => $connection->id,
            'user_id' => auth()->id(),
            'title' => $this->title ?: null,
            'sql_original' => $this->sql,
            'sql_prepared' => $result->preparedSql(),
            'statement_count' => count($result->statements),
            'is_transaction' => $result->isTransaction,
            'is_ddl' => $result->hasDdl(),
            'type' => $result->type(),
            'status' => QueryRequestStatus::Pending,
        ]);

        audit()->record('request.submitted', request: $request, sql: $request->sql_prepared, metadata: [
            'type' => $request->type->value,
            'statements' => $request->statement_count,
        ]);

        ApprovalNotifier::requestSubmitted($request);

        session()->flash('status', 'Query request #'.$request->id.' submitted for approval.');

        $this->redirectRoute('requests.show', ['queryRequest' => $request]);
    }

    public function render()
    {
        return view('livewire.studio.query-studio', [
            'connections' => $this->usableConnections(),
        ]);
    }
}

<?php

namespace App\Livewire\Studio;

use App\Models\Connection;
use App\Models\Team;
use App\Services\Requests\RequestSubmissionException;
use App\Services\Requests\RequestSubmissionService;
use App\Services\Sql\SqlInspector;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Query Studio')]
class QueryStudio extends Component
{
    public ?int $connectionId = null;

    public string $title = '';

    public string $note = '';

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

    public function submit(RequestSubmissionService $submissions): void
    {
        $this->validate([
            'connectionId' => ['required', 'integer'],
            'title' => ['nullable', 'string', 'max:150'],
            'note' => ['nullable', 'string', 'max:1000'],
            'sql' => ['required', 'string', 'max:65535'],
        ]);

        $connection = Connection::forTeam($this->team())->findOrFail($this->connectionId);

        try {
            $request = $submissions->submit(auth()->user(), $connection, $this->sql, $this->note, 'web', $this->title);
        } catch (RequestSubmissionException $e) {
            $this->violations = $e->violations;
            $this->preparedPreview = null;
            $this->previewType = null;
            $this->validated = false;

            return;
        }

        session()->flash('status', 'Query request #'.$request->id.' submitted for approval.');

        $this->redirectRoute('requests.show', ['queryRequest' => $request]);
    }

    public function render()
    {
        return view('livewire.studio.query-studio', [
            'connections' => $this->usableConnections(),
            'hasNoEnabledMaskingRules' => ! $this->team()->hasEnabledMaskingRules(),
            'hasNoMaskingRules' => ! $this->team()->maskingRules()->exists(),
        ]);
    }
}

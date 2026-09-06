<?php

namespace App\Livewire\Audit;

use App\Models\AuditLog;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Audit Log')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $action = '';

    #[Url]
    public string $actor = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public ?int $expandedId = null;

    private function team(): Team
    {
        return auth()->user()->currentTeam() ?? abort(403);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['action', 'actor', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function toggle(int $logId): void
    {
        $this->expandedId = $this->expandedId === $logId ? null : $logId;
    }

    public function filteredQuery(): Builder
    {
        return AuditLog::query()
            ->where('team_id', $this->team()->id)
            ->with(['user', 'connection'])
            ->when($this->action !== '', fn ($q) => $q->where('action', 'like', $this->action.'%'))
            ->when($this->actor !== '', fn ($q) => $q->whereHas('user', fn ($u) => $u->where('email', 'like', '%'.$this->actor.'%')
                ->orWhere('name', 'like', '%'.$this->actor.'%')))
            ->when($this->from !== '', fn ($q) => $q->where('created_at', '>=', $this->from.' 00:00:00'))
            ->when($this->to !== '', fn ($q) => $q->where('created_at', '<=', $this->to.' 23:59:59'))
            ->latest('id');
    }

    public function render()
    {
        return view('livewire.audit.index', [
            'logs' => $this->filteredQuery()->paginate(30),
            'actions' => AuditLog::where('team_id', $this->team()->id)
                ->select('action')->distinct()->orderBy('action')->pluck('action'),
        ]);
    }
}

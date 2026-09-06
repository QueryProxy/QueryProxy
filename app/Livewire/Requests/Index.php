<?php

namespace App\Livewire\Requests;

use App\Models\QueryRequest;
use App\Models\Team;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Query Requests')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $status = '';

    private function team(): Team
    {
        return auth()->user()->currentTeam() ?? abort(403);
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $user = auth()->user();
        $team = $this->team();

        $query = QueryRequest::forTeam($team)
            ->with(['connection', 'requester'])
            ->latest();

        // Developers only see their own requests; DBAs / auditors / admins see the team's.
        if (! $user->isAdmin() && $user->isDeveloperIn($team)) {
            $query->where('user_id', $user->id);
        }

        if ($this->status !== '') {
            $query->where('status', $this->status);
        }

        return view('livewire.requests.index', [
            'requests' => $query->paginate(20),
        ]);
    }
}

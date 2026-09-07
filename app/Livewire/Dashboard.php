<?php

namespace App\Livewire;

use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Dashboard')]
class Dashboard extends Component
{
    /** One of: '', pending, active, completed, failed. */
    #[Url]
    public string $filter = '';

    public function filterBy(string $filter): void
    {
        $this->filter = $this->filter === $filter ? '' : $filter;
    }

    public function approve(int $requestId, ApprovalService $approvals): void
    {
        $user = auth()->user();
        $team = $user->currentTeam() ?? abort(403);

        abort_unless($user->isAdmin() || $user->isDbaIn($team), 403);

        $request = QueryRequest::forTeam($team)->pending()->findOrFail($requestId);

        $approvals->approve($request, $user);

        session()->flash('status', "Request #{$request->id} approved and queued for execution.");
    }

    public function render()
    {
        $user = auth()->user();
        $team = $user->currentTeam();

        if (! $team) {
            return view('livewire.dashboard', [
                'team' => null,
                'counts' => collect(),
                'requests' => collect(),
                'awaiting' => collect(),
                'connections' => collect(),
                'canDecide' => false,
            ]);
        }

        $canDecide = $user->isAdmin() || $user->isDbaIn($team);

        return view('livewire.dashboard', [
            'team' => $team,
            'counts' => $this->counts($user, $team),
            'requests' => $this->recentRequests($user, $team),
            'awaiting' => $canDecide ? $this->awaitingDecision($user, $team) : collect(),
            'connections' => $this->connections($user, $team, $canDecide),
            'canDecide' => $canDecide,
        ]);
    }

    /**
     * Requests this user is allowed to see: developers only ever see their own,
     * mirroring \App\Livewire\Requests\Index.
     */
    private function visibleRequests(User $user, Team $team): Builder
    {
        return QueryRequest::forTeam($team)
            ->when(
                ! $user->isAdmin() && $user->isDeveloperIn($team),
                fn (Builder $query) => $query->where('user_id', $user->id),
            );
    }

    /** @return Collection<string, int> */
    private function counts(User $user, Team $team): Collection
    {
        return $this->visibleRequests($user, $team)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
    }

    private function recentRequests(User $user, Team $team): Collection
    {
        $statuses = match ($this->filter) {
            'pending' => ['pending'],
            'active' => ['queued', 'running'],
            'completed' => ['completed'],
            'failed' => ['failed', 'rejected'],
            default => [],
        };

        return $this->visibleRequests($user, $team)
            ->when($statuses !== [], fn (Builder $query) => $query->whereIn('status', $statuses))
            ->with(['connection', 'requester'])
            ->latest()
            ->limit(8)
            ->get();
    }

    /** Pending requests this user may actually decide — never their own, unless they are an admin. */
    private function awaitingDecision(User $user, Team $team): Collection
    {
        return QueryRequest::forTeam($team)
            ->pending()
            ->when(! $user->isAdmin(), fn (Builder $query) => $query->where('user_id', '!=', $user->id))
            ->with(['connection', 'requester'])
            ->oldest()
            ->limit(3)
            ->get();
    }

    private function connections(User $user, Team $team, bool $canDecide): Collection
    {
        return Connection::forTeam($team)
            ->when(
                ! $canDecide,
                fn (Builder $query) => $query->whereHas('grantedUsers', fn (Builder $granted) => $granted->whereKey($user->id)),
            )
            ->withCount('grantedUsers')
            ->orderBy('name')
            ->limit(6)
            ->get();
    }
}

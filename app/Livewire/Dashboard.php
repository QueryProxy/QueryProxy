<?php

namespace App\Livewire;

use App\Enums\GrantAccessLevel;
use App\Enums\GrantRequestStatus;
use App\Livewire\Concerns\ChoosesGrantDuration;
use App\Models\Connection;
use App\Models\ConnectionGrant;
use App\Models\ConnectionGrantRequest;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use App\Services\Connections\GrantException;
use App\Services\Connections\GrantService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Dashboard')]
class Dashboard extends Component
{
    use ChoosesGrantDuration;

    /** One of: '', pending, active, completed, failed. */
    #[Url]
    public string $filter = '';

    /** The connection whose access request form is open. */
    public ?int $requestingId = null;

    public string $requestLevel = 'read';

    /** A preset's minutes, or "custom". */
    public string $requestDuration = '240';

    public string $requestCustomAmount = '';

    public string $requestCustomUnit = 'hours';

    public string $requestReason = '';

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

    /**
     * Open the access request form for a connection. A developer who holds a
     * timed grant asks to extend it, at the level it already has (ADR-025);
     * a permanent grant leaves nothing to ask for.
     */
    public function startAccessRequest(int $connectionId): void
    {
        $user = auth()->user();
        $team = $user->currentTeam() ?? abort(403);

        abort_unless($user->isDeveloperIn($team), 403);

        $connection = Connection::forTeam($team)->findOrFail($connectionId);
        $grant = $connection->grantFor($user);

        $this->resetAccessRequest();

        if ($grant?->isPermanent()) {
            $this->addError('request', 'You already have permanent '.$grant->access_level->value.' access to this connection.');

            return;
        }

        $this->requestingId = $connection->id;
        $this->requestLevel = $grant?->access_level->value ?? GrantAccessLevel::Read->value;
    }

    public function cancelAccessRequest(): void
    {
        $this->resetAccessRequest();
    }

    public function requestAccess(GrantService $grants): void
    {
        $user = auth()->user();
        $team = $user->currentTeam() ?? abort(403);

        $connection = Connection::forTeam($team)->findOrFail($this->requestingId ?? abort(422));

        $this->validate([
            'requestLevel' => ['required', Rule::enum(GrantAccessLevel::class)],
            'requestReason' => ['required', 'string', 'max:1000'],
        ], attributes: ['requestLevel' => 'access level', 'requestReason' => 'reason']);

        $minutes = $this->chosenMinutes($this->requestDuration, $this->requestCustomAmount, $this->requestCustomUnit, 'requestDuration');

        if ($minutes === null) {
            return;
        }

        // The service checks that the user is an active developer of the team
        // (AuthorizationException answers 403) and refuses what ADR-025
        // refuses: a second open request, a level that differs from the grant
        // in force, a permanent grant that already covers the request.
        try {
            $grants->request($user, $connection, GrantAccessLevel::from($this->requestLevel), $minutes, $this->requestReason);
        } catch (GrantException $e) {
            $this->addError('request', $e->getMessage());

            return;
        }

        $this->resetAccessRequest();

        session()->flash('status', "Access request for {$connection->name} sent. A DBA of your team will decide.");
    }

    private function resetAccessRequest(): void
    {
        $this->reset('requestingId', 'requestLevel', 'requestDuration', 'requestCustomAmount', 'requestCustomUnit', 'requestReason');
        $this->resetErrorBag();
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
                'access' => null,
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
            'access' => $user->isDeveloperIn($team) ? $this->accessOverview($user, $team) : null,
            'durationPresets' => self::DURATION_PRESETS,
            'durationUnits' => array_keys(self::DURATION_UNITS),
        ]);
    }

    /**
     * What a developer needs to ask for access: the team's connections with
     * the grant in force on each, and their own open and recently decided
     * requests. Permanent grants are left out of the list, there is nothing
     * to ask for.
     *
     * @return array{grants: Collection, requestable: Collection, pending: Collection, decided: Collection}
     */
    private function accessOverview(User $user, Team $team): array
    {
        $grants = ConnectionGrant::query()
            ->where('user_id', $user->id)
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get()
            ->keyBy('connection_id');

        $mine = ConnectionGrantRequest::forTeam($team)->where('user_id', $user->id);

        return [
            'grants' => $grants,
            'requestable' => Connection::forTeam($team)
                ->orderBy('name')
                ->get()
                ->reject(fn (Connection $connection) => $grants->get($connection->id)?->isPermanent() === true)
                ->values(),
            'pending' => (clone $mine)->pending()->with('connection')->oldest()->get(),
            'decided' => (clone $mine)
                ->where('status', '!=', GrantRequestStatus::Pending)
                ->where('decided_at', '>=', now()->subDays(7))
                ->with('connection')
                ->latest('decided_at')
                ->limit(3)
                ->get(),
        ];
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
            ->with(['connection', 'requester', 'team'])
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

<?php

namespace App\Livewire\Approvals;

use App\Models\ConnectionGrant;
use App\Models\ConnectionGrantRequest;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Services\Approvals\ApprovalService;
use App\Services\Connections\GrantException;
use App\Services\Connections\GrantService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Approvals')]
class Index extends Component
{
    use WithPagination;

    /** "queries" or "access". */
    #[Url]
    public string $tab = 'queries';

    public ?int $rejectingId = null;

    public string $rejectionReason = '';

    public ?int $overridingId = null;

    public string $reason = '';

    public ?int $rejectingGrantId = null;

    public string $grantRejectionNote = '';

    private function team(): Team
    {
        return auth()->user()->currentTeam() ?? abort(403);
    }

    public function approve(int $requestId, ApprovalService $approvals): void
    {
        $request = QueryRequest::forTeam($this->team())->findOrFail($requestId);

        // An override carries the reason typed into the inline form; a regular
        // DBA approval has none. ApprovalService refuses an override without it.
        $reason = $this->overridingId === $request->id ? $this->reason : null;

        $approvals->approve($request, auth()->user(), 'web', $reason);

        $this->reset('overridingId', 'reason');

        session()->flash('status', "Request #{$request->id} approved and queued for execution.");
    }

    public function startOverride(int $requestId): void
    {
        $this->overridingId = $requestId;
        $this->reason = '';
        $this->rejectingId = null;
        $this->resetErrorBag();
    }

    public function startReject(int $requestId): void
    {
        $this->rejectingId = $requestId;
        $this->overridingId = null;
        $this->rejectionReason = '';
        $this->resetErrorBag();
    }

    public function reject(ApprovalService $approvals): void
    {
        $this->validate([
            'rejectionReason' => ['required', 'string', 'max:1000'],
        ], attributes: ['rejectionReason' => 'rejection reason']);

        $request = QueryRequest::forTeam($this->team())->findOrFail($this->rejectingId);

        $approvals->reject($request, auth()->user(), $this->rejectionReason);

        $this->reset('rejectingId', 'rejectionReason');
    }

    public function showTab(string $tab): void
    {
        $this->tab = $tab === 'access' ? 'access' : 'queries';
        $this->rejectingGrantId = null;
        $this->resetErrorBag();
    }

    /**
     * Approve an access request. The decision, the self-decision ban and the
     * team check all live in GrantService (ADR-025); the component only
     * shows its refusals.
     */
    public function approveGrant(int $grantRequestId, GrantService $grants): void
    {
        $this->resetErrorBag('grant');

        $grantRequest = ConnectionGrantRequest::forTeam($this->team())->findOrFail($grantRequestId);

        try {
            $decided = $grants->approve($grantRequest, auth()->user());
        } catch (GrantException $e) {
            $this->addError('grant', $e->getMessage());

            return;
        }

        $grant = $decided->connection->grantFor($decided->requester);

        session()->flash('status', sprintf(
            'Access request #%d approved: %s now has %s to %s.',
            $decided->id,
            $decided->requester->name,
            $grant?->describe() ?? 'access',
            $decided->connection->name,
        ));
    }

    public function startRejectGrant(int $grantRequestId): void
    {
        $this->rejectingGrantId = $grantRequestId;
        $this->grantRejectionNote = '';
        $this->resetErrorBag();
    }

    public function rejectGrant(GrantService $grants): void
    {
        $this->validate([
            'grantRejectionNote' => ['required', 'string', 'max:1000'],
        ], attributes: ['grantRejectionNote' => 'rejection reason']);

        $grantRequest = ConnectionGrantRequest::forTeam($this->team())->findOrFail($this->rejectingGrantId ?? abort(422));

        try {
            $decided = $grants->reject($grantRequest, auth()->user(), $this->grantRejectionNote);
        } catch (GrantException $e) {
            $this->addError('grant', $e->getMessage());
            $this->reset('rejectingGrantId', 'grantRejectionNote');

            return;
        }

        $this->reset('rejectingGrantId', 'grantRejectionNote');

        session()->flash('status', "Access request #{$decided->id} rejected.");
    }

    public function render(ApprovalService $approvals)
    {
        $team = $this->team();

        $requests = QueryRequest::forTeam($team)
            ->pending()
            ->with(['connection', 'requester', 'team'])
            ->oldest()
            ->paginate(20);

        $user = auth()->user();

        $grantRequests = ConnectionGrantRequest::forTeam($team)
            ->pending()
            ->with(['connection', 'requester'])
            ->oldest()
            ->paginate(20, pageName: 'grantsPage');

        return view('livewire.approvals.index', [
            'requests' => $requests,
            'grantRequests' => $grantRequests,
            'currentGrants' => $this->currentGrants($grantRequests->getCollection()),
            'overrideIds' => $requests->getCollection()
                ->filter(fn (QueryRequest $r) => $approvals->isOverride($r, $user))
                ->pluck('id')
                ->all(),
        ]);
    }

    /**
     * The grant each requester holds now, keyed "connection:user", so the card
     * can say what an approval would extend.
     *
     * @param  Collection<int, ConnectionGrantRequest>  $grantRequests
     * @return Collection<string, ConnectionGrant>
     */
    private function currentGrants(Collection $grantRequests): Collection
    {
        if ($grantRequests->isEmpty()) {
            return collect();
        }

        return ConnectionGrant::query()
            ->whereIn('connection_id', $grantRequests->pluck('connection_id'))
            ->whereIn('user_id', $grantRequests->pluck('user_id'))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get()
            ->keyBy(fn (ConnectionGrant $grant) => $grant->connection_id.':'.$grant->user_id);
    }
}

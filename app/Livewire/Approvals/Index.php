<?php

namespace App\Livewire\Approvals;

use App\Models\QueryRequest;
use App\Models\Team;
use App\Services\Approvals\ApprovalService;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Approvals')]
class Index extends Component
{
    use WithPagination;

    public ?int $rejectingId = null;

    public string $rejectionReason = '';

    public ?int $overridingId = null;

    public string $reason = '';

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

    public function render(ApprovalService $approvals)
    {
        $requests = QueryRequest::forTeam($this->team())
            ->pending()
            ->with(['connection', 'requester', 'team'])
            ->oldest()
            ->paginate(20);

        $user = auth()->user();

        return view('livewire.approvals.index', [
            'requests' => $requests,
            'overrideIds' => $requests->getCollection()
                ->filter(fn (QueryRequest $r) => $approvals->isOverride($r, $user))
                ->pluck('id')
                ->all(),
        ]);
    }
}

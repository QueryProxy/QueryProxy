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

    private function team(): Team
    {
        return auth()->user()->currentTeam() ?? abort(403);
    }

    public function approve(int $requestId, ApprovalService $approvals): void
    {
        $request = QueryRequest::forTeam($this->team())->findOrFail($requestId);

        $approvals->approve($request, auth()->user());

        session()->flash('status', "Request #{$request->id} approved and queued for execution.");
    }

    public function startReject(int $requestId): void
    {
        $this->rejectingId = $requestId;
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

    public function render()
    {
        return view('livewire.approvals.index', [
            'requests' => QueryRequest::forTeam($this->team())
                ->pending()
                ->with(['connection', 'requester'])
                ->oldest()
                ->paginate(20),
        ]);
    }
}

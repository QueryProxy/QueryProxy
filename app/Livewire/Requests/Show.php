<?php

namespace App\Livewire\Requests;

use App\Models\QueryRequest;
use App\Services\Approvals\ApprovalService;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Query Request')]
class Show extends Component
{
    public QueryRequest $queryRequest;

    public string $rejectionReason = '';

    public bool $showRejectForm = false;

    public function mount(): void
    {
        $this->authorize('view', $this->queryRequest);
    }

    public function cancel(ApprovalService $approvals): void
    {
        $approvals->cancel($this->queryRequest, auth()->user());
        $this->queryRequest->refresh();
    }

    public function approve(ApprovalService $approvals): void
    {
        $approvals->approve($this->queryRequest, auth()->user());
        $this->queryRequest->refresh();
    }

    public function reject(ApprovalService $approvals): void
    {
        $this->validate([
            'rejectionReason' => ['required', 'string', 'max:1000'],
        ], attributes: ['rejectionReason' => 'rejection reason']);

        $approvals->reject($this->queryRequest, auth()->user(), $this->rejectionReason);
        $this->showRejectForm = false;
        $this->queryRequest->refresh();
    }

    public function render()
    {
        return view('livewire.requests.show', [
            'request' => $this->queryRequest->load(['connection', 'requester', 'reviewer']),
            'canReview' => auth()->user()->can('review', $this->queryRequest),
            'canCancel' => auth()->user()->can('cancel', $this->queryRequest),
        ]);
    }
}

<?php

namespace App\Livewire\Approvals;

use App\Models\QueryRequest;
use App\Services\Approvals\ApprovalService;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The reason form for an override approval. Chat cards have nowhere to type a
 * reason, so a Slack "Approve" that would be an override lands here; the
 * override is applied only once a written reason is submitted. The signed-in
 * web session is the identity check: the request policy decides who may review.
 */
#[Title('Override approval')]
class Override extends Component
{
    public QueryRequest $queryRequest;

    public string $reason = '';

    public function mount(ApprovalService $approvals): void
    {
        $this->authorize('view', $this->queryRequest);

        $user = auth()->user();

        if (! $this->queryRequest->isPending()) {
            session()->flash('status', "Request #{$this->queryRequest->id} has already been decided.");
            $this->redirectRoute('requests.show', ['queryRequest' => $this->queryRequest], navigate: false);

            return;
        }

        $this->authorize('review', $this->queryRequest);

        if (! $approvals->isOverride($this->queryRequest, $user)) {
            // A regular DBA approval needs no reason: send them to the normal screen.
            $this->redirectRoute('requests.show', ['queryRequest' => $this->queryRequest], navigate: false);
        }
    }

    public function approve(ApprovalService $approvals): void
    {
        $this->validate([
            'reason' => ['required', 'string', 'max:'.ApprovalService::MAX_REASON_LENGTH],
        ]);

        try {
            $approvals->approve($this->queryRequest, auth()->user(), 'web', $this->reason);
        } catch (AuthorizationException) {
            session()->flash('status', "Request #{$this->queryRequest->id} could not be approved: it was already decided or you may not review it.");
            $this->redirectRoute('requests.show', ['queryRequest' => $this->queryRequest], navigate: false);

            return;
        }

        session()->flash('status', "Request #{$this->queryRequest->id} approved by override and queued for execution.");
        $this->redirectRoute('requests.show', ['queryRequest' => $this->queryRequest], navigate: false);
    }

    public function render()
    {
        return view('livewire.approvals.override', [
            'request' => $this->queryRequest->load(['connection', 'requester']),
        ]);
    }
}

<?php

namespace App\Livewire\Requests;

use App\Models\QueryRequest;
use App\Services\Approvals\ApprovalService;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Query Request')]
class Show extends Component
{
    public QueryRequest $queryRequest;

    public string $rejectionReason = '';

    public bool $showRejectForm = false;

    /** Shown above the request when a stop did not reach the database. */
    public ?string $notice = null;

    public function mount(): void
    {
        $this->authorize('view', $this->queryRequest);
    }

    public function cancel(ApprovalService $approvals): void
    {
        $approvals->cancel($this->queryRequest, auth()->user());
        $this->queryRequest->refresh();
    }

    public function stop(ApprovalService $approvals): void
    {
        // A query that ended while the page was open is the usual way to land
        // here; that is a message, not a "not allowed" error page. A user who
        // may not stop a still-running request gets the policy's 403 below.
        $this->queryRequest->refresh();

        if (! $this->queryRequest->isStoppable()) {
            $this->notice = 'This request is no longer running.';

            return;
        }

        try {
            $signalled = $approvals->stop($this->queryRequest, auth()->user());
        } catch (AuthorizationException $e) {
            $this->queryRequest->refresh();

            if ($this->queryRequest->isStoppable()) {
                throw $e;
            }

            $this->notice = 'This request is no longer running.';

            return;
        }

        $this->queryRequest->refresh();

        $this->notice = $signalled
            ? null
            : 'The request is marked cancelled, but the database could not be reached to cancel the statement. It ends on its own at the latest when the statement timeout is reached.';
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
            'isOverride' => $this->queryRequest->isPending()
                && app(ApprovalService::class)->isOverride($this->queryRequest, auth()->user()),
            'canCancel' => auth()->user()->can('cancel', $this->queryRequest),
            'canStop' => auth()->user()->can('stop', $this->queryRequest),
        ]);
    }
}

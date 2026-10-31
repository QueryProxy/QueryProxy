<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\QueryRequestResource;
use App\Models\QueryRequest;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Approve, reject and cancel through the same ApprovalService the web UI and
 * the chat callbacks use, so ADR-008 (no self-review, override rules) and the
 * request state machine apply unchanged.
 */
class ApprovalController extends Controller
{
    private const CHANNEL = 'api';

    public function __construct(private readonly ApprovalService $approvals) {}

    /**
     * An approval that would be an override (ADR-008: the reviewer is not a DBA
     * of the request's team, or the request is their own) is never applied
     * here. It needs a written reason, taken in the web UI, so the answer is a
     * 403 with the link to that form. A token therefore can never approve its
     * owner's own request, whatever role the owner has.
     */
    public function approve(Request $request, QueryRequest $queryRequest): QueryRequestResource|JsonResponse
    {
        $user = $request->user();

        $this->guardDecidable($user, $queryRequest);

        if ($this->approvals->isOverride($queryRequest, $user)) {
            return response()->json([
                'message' => 'Approving this request would be an override, which needs a written reason and cannot be done through the API. Use the web form.',
                'override_url' => route('requests.override', $queryRequest),
            ], 403);
        }

        $this->decide(fn () => $this->approvals->approve($queryRequest, $user, self::CHANNEL), $queryRequest);

        return new QueryRequestResource($queryRequest->refresh());
    }

    public function reject(Request $request, QueryRequest $queryRequest): QueryRequestResource
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:'.ApprovalService::MAX_REASON_LENGTH],
        ]);

        $user = $request->user();

        $this->guardDecidable($user, $queryRequest);

        $this->decide(fn () => $this->approvals->reject($queryRequest, $user, $data['reason'], self::CHANNEL), $queryRequest);

        return new QueryRequestResource($queryRequest->refresh());
    }

    /** Withdraw a pending request: its requester or an admin (QueryRequestPolicy::cancel). */
    public function cancel(Request $request, QueryRequest $queryRequest): QueryRequestResource
    {
        $user = $request->user();

        $this->guardDecidable($user, $queryRequest, ability: 'cancel');

        $this->decide(fn () => $this->approvals->cancel($queryRequest, $user, self::CHANNEL), $queryRequest);

        return new QueryRequestResource($queryRequest->refresh());
    }

    /**
     * Who may know the request exists (`view`) is checked first, so a caller
     * without that right gets a 403 and learns nothing about its state; for the
     * rest, a request that is no longer pending is a 409, not a permission
     * problem. The permission rules themselves are the service's.
     */
    private function guardDecidable(User $user, QueryRequest $queryRequest, string $ability = 'review'): void
    {
        Gate::forUser($user)->authorize('view', $queryRequest);

        abort_unless($queryRequest->isPending(), 409, 'This request has already been decided.');

        Gate::forUser($user)->authorize($ability, $queryRequest);
    }

    /** The service claims the request atomically; losing that race to another decision is a 409 as well. */
    private function decide(callable $action, QueryRequest $queryRequest): void
    {
        try {
            $action();
        } catch (AuthorizationException $e) {
            if (! $queryRequest->fresh()?->isPending()) {
                abort(409, 'This request has already been decided.');
            }

            throw $e;
        }
    }
}

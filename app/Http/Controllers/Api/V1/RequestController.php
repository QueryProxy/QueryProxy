<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\QueryRequestResource;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Services\Requests\RequestSubmissionException;
use App\Services\Requests\RequestSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RequestController extends Controller
{
    /**
     * Submit a request. It lands as `pending` like any other: nothing runs
     * until a reviewer approves it (ADR-010).
     */
    public function store(Request $request, RequestSubmissionService $submissions): JsonResponse
    {
        $data = $request->validate([
            'connection_id' => ['required', 'integer'],
            'sql' => ['required', 'string', 'max:65535'],
            'title' => ['nullable', 'string', 'max:150'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = $request->user();

        // A connection the caller cannot see is reported as missing, so ids
        // cannot be probed across teams. One they can see but may not use
        // (an auditor, a read-only grant on a write) is refused by the service.
        $connection = Connection::find($data['connection_id']);

        abort_unless($connection !== null && Gate::forUser($user)->allows('view', $connection), 404, 'Connection not found.');

        try {
            $queryRequest = $submissions->submit(
                $user,
                $connection,
                $data['sql'],
                $data['reason'] ?? null,
                'api',
                $data['title'] ?? null,
            );
        } catch (RequestSubmissionException $e) {
            return response()->json([
                'message' => 'The SQL guard refused the statement.',
                'violations' => $e->violations,
            ], 422);
        }

        return (new QueryRequestResource($queryRequest))
            ->response()
            ->setStatusCode(201)
            ->header('Location', route('api.v1.requests.show', $queryRequest));
    }

    public function show(Request $request, QueryRequest $queryRequest): QueryRequestResource
    {
        Gate::forUser($request->user())->authorize('view', $queryRequest);

        return new QueryRequestResource($queryRequest);
    }
}

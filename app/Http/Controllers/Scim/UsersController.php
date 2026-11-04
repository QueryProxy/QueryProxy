<?php

namespace App\Http\Controllers\Scim;

use App\Services\Scim\ScimUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /scim/v2/Users (ADR-026). */
class UsersController extends ScimController
{
    public function __construct(private readonly ScimUserService $users) {}

    public function index(Request $request): JsonResponse
    {
        $filter = $request->query('filter');

        return $this->respond($this->users->list(
            is_string($filter) ? $filter : null,
            $this->startIndex($request),
            $this->count($request),
        ));
    }

    public function show(string $id): JsonResponse
    {
        return $this->respond($this->users->resource($this->users->find($id)));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->users->create($this->body($request));

        return $this->respond($this->users->resource($user), 201, [
            'Location' => route('scim.users.show', ['id' => $user->id]),
        ]);
    }

    public function replace(Request $request, string $id): JsonResponse
    {
        $user = $this->users->replace($this->users->find($id), $this->body($request));

        return $this->respond($this->users->resource($user));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = $this->users->patch($this->users->find($id), $this->body($request));

        return $this->respond($this->users->resource($user));
    }

    /** Suspends the user; nothing is deleted. */
    public function destroy(string $id): JsonResponse
    {
        $this->users->delete($this->users->find($id));

        return $this->noContent();
    }
}

<?php

namespace App\Http\Controllers\Scim;

use App\Services\Scim\ScimGroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /scim/v2/Groups (ADR-026). */
class GroupsController extends ScimController
{
    public function __construct(private readonly ScimGroupService $groups) {}

    public function index(Request $request): JsonResponse
    {
        $filter = $request->query('filter');

        return $this->respond($this->groups->list(
            is_string($filter) ? $filter : null,
            $this->startIndex($request),
            $this->count($request),
            $this->withMembers($request),
        ));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->respond($this->groups->resource($this->groups->find($id), $this->withMembers($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $group = $this->groups->create($this->body($request));

        return $this->respond($this->groups->resource($group), 201, [
            'Location' => route('scim.groups.show', ['id' => $group->id]),
        ]);
    }

    public function replace(Request $request, string $id): JsonResponse
    {
        return $this->respond($this->groups->resource(
            $this->groups->replace($this->groups->find($id), $this->body($request)),
        ));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return $this->respond($this->groups->resource(
            $this->groups->patch($this->groups->find($id), $this->body($request)),
        ));
    }

    public function destroy(string $id): JsonResponse
    {
        $this->groups->delete($this->groups->find($id));

        return $this->noContent();
    }

    /** `excludedAttributes=members` (Entra ID asks for it on every list) leaves the member list out. */
    private function withMembers(Request $request): bool
    {
        $value = $request->query('excludedAttributes');
        $excluded = array_map('trim', explode(',', is_string($value) ? strtolower($value) : ''));

        return ! in_array('members', $excluded, true);
    }
}

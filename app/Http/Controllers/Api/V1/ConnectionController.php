<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ConnectionResource;
use App\Models\Connection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ConnectionController extends Controller
{
    /**
     * The connections the token's owner may see: all of them for an admin; for
     * everyone else those of the teams they are DBA or auditor in (the cases
     * ConnectionPolicy::view admits by role) plus the ones with a grant that is
     * still in force.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $connections = Connection::query()
            ->when(! $user->isAdmin(), function ($query) use ($user) {
                $teamIds = $user->teams()
                    ->wherePivotIn('role', [TeamRole::Dba->value, TeamRole::Auditor->value])
                    ->pluck('teams.id');

                $query->where(fn ($visible) => $visible
                    ->whereIn('team_id', $teamIds)
                    ->orWhereHas('grantedUsers', fn ($granted) => $granted->whereKey($user->id)));
            })
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return ConnectionResource::collection($connections);
    }
}

<?php

namespace App\Http\Middleware;

use App\Enums\TeamRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTeamRole
{
    /**
     * Usage: ->middleware('role:dba') or ->middleware('role:dba,developer').
     * System admins always pass.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        abort_unless($user, 403);

        if ($user->isAdmin()) {
            return $next($request);
        }

        $team = $user->currentTeam();

        abort_unless($team, 403);

        $role = $user->roleIn($team);

        abort_unless($role && in_array($role, array_map(TeamRole::from(...), $roles), true), 403);

        return $next($request);
    }
}

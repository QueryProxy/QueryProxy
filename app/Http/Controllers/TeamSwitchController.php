<?php

namespace App\Http\Controllers;

use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TeamSwitchController extends Controller
{
    public function __invoke(Request $request, Team $team): RedirectResponse
    {
        abort_unless($request->user()->belongsToTeam($team), 403);

        session(['current_team_id' => $team->id]);

        return redirect()->route('dashboard');
    }
}

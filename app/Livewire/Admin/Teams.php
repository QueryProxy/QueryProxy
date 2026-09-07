<?php

namespace App\Livewire\Admin;

use App\Models\QueryRequest;
use App\Models\Team;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Teams')]
class Teams extends Component
{
    public string $name = '';

    /**
     * Route middleware only guards the initial page load; every action must
     * re-check because Livewire updates arrive on a separate endpoint and the
     * actor's privileges may have been revoked since the page was served.
     */
    private function assertAdmin(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function createTeam(): void
    {
        $this->assertAdmin();

        $this->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $slug = Str::slug($this->name);
        $base = $slug;
        $i = 1;

        while (Team::where('slug', $slug)->exists()) {
            $slug = $base.'-'.++$i;
        }

        $team = Team::create(['name' => $this->name, 'slug' => $slug]);

        audit()->record('team.created', team: $team, metadata: ['name' => $team->name]);

        $this->reset('name');
        session()->flash('status', "Team \"{$team->name}\" created.");
        $this->redirectRoute('admin.teams.members', ['team' => $team]);
    }

    public function deleteTeam(int $teamId): void
    {
        $this->assertAdmin();

        $team = Team::findOrFail($teamId);

        audit()->record('team.deleted', team: $team, metadata: ['name' => $team->name]);

        // The DB cascade removes the query_requests rows without firing model
        // events, so the stored result files must be deleted explicitly or
        // they would linger on disk beyond the pruner's reach.
        QueryRequest::where('team_id', $team->id)
            ->whereNotNull('result_path')
            ->lazyById()
            ->each(function (QueryRequest $request) {
                Storage::disk($request->result_disk ?? config('queryproxy.result_disk', 'local'))
                    ->delete($request->result_path);
            });

        $team->delete();
    }

    public function render()
    {
        return view('livewire.admin.teams', [
            'teams' => Team::withCount('users')->orderBy('name')->get(),
        ]);
    }
}

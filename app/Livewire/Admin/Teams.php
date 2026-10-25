<?php

namespace App\Livewire\Admin;

use App\Models\QueryRequest;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
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

        // The team row, its default masking rules (Team's `created` hook) and
        // the audit entry land together or not at all.
        $team = DB::transaction(function () use ($slug) {
            $team = Team::create(['name' => $this->name, 'slug' => $slug]);

            audit()->record('team.created', team: $team, metadata: ['name' => $team->name]);

            return $team;
        }, attempts: 3);

        $this->reset('name');
        session()->flash('status', "Team \"{$team->name}\" created.");
        $this->redirectRoute('admin.teams.members', ['team' => $team]);
    }

    public function deleteTeam(int $teamId): void
    {
        $this->assertAdmin();

        // Audit record and delete land together, and the transaction is opened
        // with `attempts` because it writes an audit record: a concurrent audit
        // write can fail the chain lock with error 1020 on MariaDB 11.8, and
        // only the outermost transaction is retried. The closure is safe to
        // run again: deleting result files is idempotent, and the team's TLS
        // PEM files go only after commit (Team's `deleted` hook).
        DB::transaction(function () use ($teamId) {
            // Loaded afresh on every attempt: a model whose delete was rolled
            // back would otherwise still believe it no longer exists.
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
        }, attempts: 3);
    }

    public function render()
    {
        return view('livewire.admin.teams', [
            'teams' => Team::withCount('users')->orderBy('name')->get(),
        ]);
    }
}

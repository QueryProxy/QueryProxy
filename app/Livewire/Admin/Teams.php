<?php

namespace App\Livewire\Admin;

use App\Models\QueryRequest;
use App\Models\Team;
use App\Services\Secrets\SecretReference;
use App\Services\Secrets\TeamSecretPrefixes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Teams')]
class Teams extends Component
{
    public string $name = '';

    /** The team whose secret reference prefixes are open (ADR-028 amendment). */
    public ?int $prefixesForId = null;

    public string $newPrefix = '';

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

    public function togglePrefixes(int $teamId): void
    {
        $this->assertAdmin();

        $this->prefixesForId = $this->prefixesForId === $teamId ? null : $teamId;
        $this->reset('newPrefix');
        $this->resetErrorBag();
    }

    /**
     * Allow a team to reference secrets under a prefix. The prefix is
     * validated and stored by TeamSecretPrefixes (which audits the change).
     */
    public function addPrefix(int $teamId, TeamSecretPrefixes $prefixes): void
    {
        $this->assertAdmin();

        $team = Team::findOrFail($teamId);

        $this->resetErrorBag();

        if (trim($this->newPrefix) === '') {
            $this->addError('newPrefix', 'Enter a prefix, for example vault://secret/teams/payments.');

            return;
        }

        try {
            $prefixes->set($team, [...$team->secretReferencePrefixes(), $this->newPrefix], auth()->user());
        } catch (ValidationException $e) {
            $this->addError('newPrefix', $e->errors()['secret_reference_prefixes'][0] ?? 'This prefix is not valid.');

            return;
        }

        $this->reset('newPrefix');
    }

    public function removePrefix(int $teamId, string $prefix, TeamSecretPrefixes $prefixes): void
    {
        $this->assertAdmin();

        $team = Team::findOrFail($teamId);

        $prefixes->set($team, array_values(array_diff($team->secretReferencePrefixes(), [$prefix])), auth()->user());
    }

    /**
     * Teams, other than $team, whose prefixes overlap with its own (equal, or
     * one inside the other). A reference under the shared part is open to both
     * teams, which an administrator rarely means.
     *
     * @param  Collection<int, Team>  $teams
     * @return array<string, list<string>> prefix => names of the other teams
     */
    private function overlaps(Team $team, $teams): array
    {
        $overlaps = [];

        foreach ($team->secretReferencePrefixes() as $prefix) {
            foreach ($teams as $other) {
                if ($other->is($team)) {
                    continue;
                }

                foreach ($other->secretReferencePrefixes() as $theirs) {
                    if ($prefix === $theirs || str_starts_with($prefix, $theirs.'/') || str_starts_with($theirs, $prefix.'/')) {
                        $overlaps[$prefix][] = $other->name;
                    }
                }
            }
        }

        return array_map(fn (array $names) => array_values(array_unique($names)), $overlaps);
    }

    public function render()
    {
        $teams = Team::withCount('users')->orderBy('name')->get();
        $open = $this->prefixesForId ? $teams->firstWhere('id', $this->prefixesForId) : null;

        return view('livewire.admin.teams', [
            'teams' => $teams,
            'openTeam' => $open,
            'openPrefixes' => $open?->secretReferencePrefixes() ?? [],
            'overlaps' => $open ? $this->overlaps($open, $teams) : [],
            'prefixSchemes' => SecretReference::schemes(),
        ]);
    }
}

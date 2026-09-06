<?php

namespace App\Livewire\Admin;

use App\Models\Team;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Teams')]
class Teams extends Component
{
    public string $name = '';

    public function createTeam(): void
    {
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
        $team = Team::findOrFail($teamId);

        audit()->record('team.deleted', team: $team, metadata: ['name' => $team->name]);

        $team->delete();
    }

    public function render()
    {
        return view('livewire.admin.teams', [
            'teams' => Team::withCount('users')->orderBy('name')->get(),
        ]);
    }
}

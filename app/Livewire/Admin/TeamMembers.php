<?php

namespace App\Livewire\Admin;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Team Members')]
class TeamMembers extends Component
{
    public Team $team;

    public string $email = '';

    public string $role = 'developer';

    public function addMember(): void
    {
        $this->validate([
            'email' => ['required', 'email'],
            'role' => ['required', Rule::enum(TeamRole::class)],
        ]);

        $user = User::where('email', $this->email)->first();

        if (! $user) {
            $this->addError('email', 'No user exists with this email. Create the user first (Admin → Users).');

            return;
        }

        if ($this->team->users()->whereKey($user->id)->exists()) {
            $this->addError('email', 'This user is already a member of the team.');

            return;
        }

        $this->team->users()->attach($user, ['role' => $this->role]);

        audit()->record('team.member_added', team: $this->team, metadata: [
            'member' => $user->email,
            'role' => $this->role,
        ]);

        $this->reset('email');
    }

    public function updateRole(int $userId, string $role): void
    {
        abort_unless(TeamRole::tryFrom($role) !== null, 422);

        $this->team->users()->updateExistingPivot($userId, ['role' => $role]);

        audit()->record('team.member_role_changed', team: $this->team, metadata: [
            'member_id' => $userId,
            'role' => $role,
        ]);
    }

    public function removeMember(int $userId): void
    {
        $user = User::findOrFail($userId);
        $this->team->users()->detach($userId);

        audit()->record('team.member_removed', team: $this->team, metadata: [
            'member' => $user->email,
        ]);
    }

    public function render()
    {
        return view('livewire.admin.team-members', [
            'members' => $this->team->users()->orderBy('name')->get(),
            'roles' => TeamRole::cases(),
        ]);
    }
}

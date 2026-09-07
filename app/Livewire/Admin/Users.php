<?php

namespace App\Livewire\Admin;

use App\Models\ChatIdentity;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Users')]
class Users extends Component
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public bool $isAdmin = false;

    /**
     * Route middleware only guards the initial page load; every action must
     * re-check because Livewire updates arrive on a separate endpoint and the
     * actor's privileges may have been revoked since the page was served.
     */
    private function assertAdmin(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function createUser(): void
    {
        $this->assertAdmin();

        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        $password = $this->password !== '' ? $this->password : Str::password(16);

        $user = User::create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => $password,
            'is_admin' => $this->isAdmin,
        ]);

        audit()->record('user.created', metadata: [
            'user' => $user->email,
            'is_admin' => $user->is_admin,
        ]);

        if ($this->password === '') {
            // Flash instead of a public property: component state round-trips
            // through every subsequent Livewire request, a flash shows once.
            session()->flash('generated_password', $password);
        }

        $this->reset('name', 'email', 'password', 'isAdmin');
        session()->flash('status', "User \"{$user->email}\" created.");
    }

    public function toggleAdmin(int $userId): void
    {
        $this->assertAdmin();

        $user = User::findOrFail($userId);

        if ($user->id === auth()->id()) {
            $this->addError('email', 'You cannot change your own admin status.');

            return;
        }

        $user->update(['is_admin' => ! $user->is_admin]);

        audit()->record('user.admin_toggled', metadata: [
            'user' => $user->email,
            'is_admin' => $user->is_admin,
        ]);
    }

    /** Generate a fresh password for a locked-out user; shown once, like on creation. */
    public function resetPassword(int $userId): void
    {
        $this->assertAdmin();

        $user = User::findOrFail($userId);

        if ($user->id === auth()->id()) {
            $this->addError('email', 'Change your own password from your profile page.');

            return;
        }

        $password = Str::password(16);
        $user->update([
            'password' => $password,
            'remember_token' => Str::random(60),
        ]);

        // A password reset must also end the user's live sessions — otherwise
        // an attacker holding a stolen session simply keeps it.
        DB::table('sessions')->where('user_id', $user->id)->delete();

        audit()->record('user.password_reset', metadata: ['user' => $user->email]);

        session()->flash('generated_password', $password);
        session()->flash('status', "New password generated for \"{$user->email}\".");
    }

    /** Unlock a user who lost their authenticator: clears secret + recovery codes. */
    public function resetTwoFactor(int $userId, TwoFactorService $twoFactor): void
    {
        $this->assertAdmin();

        $user = User::findOrFail($userId);

        $twoFactor->disable($user);

        audit()->record('user.two_factor_reset', metadata: ['user' => $user->email]);

        session()->flash('status', "Two-factor authentication reset for \"{$user->email}\".");
    }

    public function deleteUser(int $userId): void
    {
        $this->assertAdmin();

        $user = User::findOrFail($userId);

        if ($user->id === auth()->id()) {
            $this->addError('email', 'You cannot delete your own account.');

            return;
        }

        audit()->record('user.deleted', metadata: ['user' => $user->email]);

        $user->delete();
    }

    public function updateSlackId(int $userId, string $slackId): void
    {
        $this->updateChatId($userId, 'slack', $slackId);
    }

    public function updateTeamsId(int $userId, string $teamsId): void
    {
        $this->updateChatId($userId, 'teams', $teamsId);
    }

    private function updateChatId(int $userId, string $provider, string $externalId): void
    {
        $this->assertAdmin();

        abort_unless(in_array($provider, ['slack', 'teams'], true), 422);

        $user = User::findOrFail($userId);
        $externalId = trim($externalId);

        if ($externalId === '') {
            ChatIdentity::where('user_id', $user->id)->where('provider', $provider)->delete();
            audit()->record('chat_identity.removed', metadata: ['user' => $user->email, 'provider' => $provider]);

            return;
        }

        $taken = ChatIdentity::where('provider', $provider)
            ->where('external_id', $externalId)
            ->where('user_id', '!=', $user->id)
            ->exists();

        if ($taken) {
            $this->addError('email', ucfirst($provider)." ID {$externalId} is already linked to another user.");

            return;
        }

        ChatIdentity::updateOrCreate(
            ['user_id' => $user->id, 'provider' => $provider],
            ['external_id' => $externalId],
        );

        audit()->record('chat_identity.linked', metadata: [
            'user' => $user->email, 'provider' => $provider, 'external_id' => $externalId,
        ]);
    }

    public function render()
    {
        return view('livewire.admin.users', [
            'users' => User::withCount('teams')->orderBy('name')->get(),
            'slackIds' => ChatIdentity::where('provider', 'slack')->pluck('external_id', 'user_id'),
            'teamsIds' => ChatIdentity::where('provider', 'teams')->pluck('external_id', 'user_id'),
        ]);
    }
}

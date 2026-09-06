<?php

namespace App\Livewire\Admin;

use App\Models\User;
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

    /** Freshly generated password to show once after creation. */
    public ?string $generatedPassword = null;

    public function createUser(): void
    {
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

        $this->generatedPassword = $this->password === '' ? $password : null;
        $this->reset('name', 'email', 'password', 'isAdmin');
        session()->flash('status', "User \"{$user->email}\" created.");
    }

    public function toggleAdmin(int $userId): void
    {
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

    public function deleteUser(int $userId): void
    {
        $user = User::findOrFail($userId);

        if ($user->id === auth()->id()) {
            $this->addError('email', 'You cannot delete your own account.');

            return;
        }

        audit()->record('user.deleted', metadata: ['user' => $user->email]);

        $user->delete();
    }

    public function render()
    {
        return view('livewire.admin.users', [
            'users' => User::withCount('teams')->orderBy('name')->get(),
        ]);
    }
}

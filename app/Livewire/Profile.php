<?php

namespace App\Livewire;

use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Profile')]
class Profile extends Component
{
    public string $name = '';

    public string $currentPassword = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        $this->name = auth()->user()->name;
    }

    public function updateName(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        auth()->user()->update(['name' => $this->name]);

        audit()->record('user.name_changed');

        session()->flash('status', 'Name updated.');
    }

    public function updatePassword(): void
    {
        $this->validate([
            'currentPassword' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], attributes: [
            'currentPassword' => 'current password',
        ]);

        auth()->user()->update(['password' => $this->password]);

        audit()->record('user.password_changed');

        $this->reset('currentPassword', 'password', 'password_confirmation');

        session()->flash('status', 'Password changed.');
    }

    public function render()
    {
        return view('livewire.profile');
    }
}

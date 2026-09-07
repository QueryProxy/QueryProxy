<div class="mx-auto max-w-xl">
    <x-ui.page-header title="Profile" :subtitle="auth()->user()->email" />

    @if (session('status'))
        <x-ui.alert tone="ok" icon="check" class="mb-3.5">{{ session('status') }}</x-ui.alert>
    @endif

    <x-ui.panel title="Name" padded class="mb-3.5">
        <form wire:submit="updateName" class="flex items-end gap-2.5">
            <x-ui.field class="flex-1" :error="$errors->first('name')">
                <x-ui.input type="text" wire:model="name" />
            </x-ui.field>
            <x-ui.btn type="submit" variant="primary">Save</x-ui.btn>
        </form>
    </x-ui.panel>

    <x-ui.panel title="Change password" padded>
        <form wire:submit="updatePassword" class="flex flex-col gap-3.5">
            <x-ui.field label="Current password" :error="$errors->first('currentPassword')">
                <x-ui.input type="password" wire:model="currentPassword" autocomplete="current-password" />
            </x-ui.field>

            <x-ui.field label="New password" :error="$errors->first('password')">
                <x-ui.input type="password" wire:model="password" autocomplete="new-password" />
            </x-ui.field>

            <x-ui.field label="New password (again)">
                <x-ui.input type="password" wire:model="password_confirmation" autocomplete="new-password" />
            </x-ui.field>

            <x-ui.btn type="submit" variant="primary" class="self-start">Change password</x-ui.btn>
        </form>
    </x-ui.panel>
</div>

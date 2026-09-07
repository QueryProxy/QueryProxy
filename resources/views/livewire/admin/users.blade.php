<div>
    <div class="mb-5">
        <a href="{{ route('admin.teams') }}" class="inline-flex items-center gap-1.5 font-mono text-[11.5px] text-mute-3 transition-colors hover:text-ink-3">
            <x-ui.icon name="arrow-left" :size="14" />
            Teams
        </a>
        <h1 class="mt-1.5 font-display text-[19px] font-semibold tracking-[-0.012em] text-ink">Users</h1>
        <p class="mt-1.5 max-w-[78ch] text-[12.5px] leading-[1.55] text-mute">
            Create accounts, grant system-admin, or remove users. Team roles are managed per team.
        </p>
    </div>

    @if ($generatedPassword)
        <x-ui.alert tone="pending" icon="key" class="mb-3.5">
            Generated password (shown once):
            <code class="ml-1 rounded-badge border border-pending-line bg-canvas px-1.5 py-0.5 font-mono text-pending">{{ $generatedPassword }}</code>
        </x-ui.alert>
    @endif

    <x-ui.panel padded class="mb-3.5">
        <form wire:submit="createUser" class="grid grid-cols-1 items-end gap-3 sm:grid-cols-5">
            <x-ui.field label="Name" :error="$errors->first('name')">
                <x-ui.input type="text" wire:model="name" />
            </x-ui.field>

            <x-ui.field label="Email" :error="$errors->first('email')">
                <x-ui.input type="email" wire:model="email" />
            </x-ui.field>

            <x-ui.field label="Password" hint="blank = generate" :error="$errors->first('password')">
                <x-ui.input type="text" wire:model="password" class="font-mono" />
            </x-ui.field>

            <label class="flex h-[30px] items-center gap-2 text-[12.5px] text-ink-4">
                <x-ui.checkbox wire:model="isAdmin" />
                System admin
            </label>

            <x-ui.btn type="submit" variant="primary" icon="plus">Create user</x-ui.btn>
        </form>
    </x-ui.panel>

    <x-ui.panel>
        <x-ui.table>
            <x-slot:head>
                <tr>
                    <x-ui.th>Name</x-ui.th>
                    <x-ui.th class="w-64">Email</x-ui.th>
                    <x-ui.th class="w-20">Teams</x-ui.th>
                    <x-ui.th class="w-36">Slack ID</x-ui.th>
                    <x-ui.th class="w-20">Admin</x-ui.th>
                    <x-ui.th class="w-72" align="right"></x-ui.th>
                </tr>
            </x-slot:head>

            @foreach ($users as $u)
                <x-ui.tr>
                    <x-ui.td class="text-ink-2">{{ $u->name }}</x-ui.td>
                    <x-ui.td mono muted>{{ $u->email }}</x-ui.td>
                    <x-ui.td mono>{{ $u->teams_count }}</x-ui.td>
                    <x-ui.td>
                        <x-ui.input type="text" value="{{ $slackIds[$u->id] ?? '' }}" placeholder="U0123ABC"
                                    wire:change="updateSlackId({{ $u->id }}, $event.target.value)"
                                    class="h-[26px] font-mono text-[11.5px]" />
                    </x-ui.td>
                    <x-ui.td>
                        @if ($u->is_admin)
                            <x-ui.badge tone="pending">Admin</x-ui.badge>
                        @else
                            <span class="text-mute-4">—</span>
                        @endif
                    </x-ui.td>
                    <x-ui.td align="right" nowrap>
                        @if ($u->id !== auth()->id())
                            <div class="flex items-center justify-end gap-2.5">
                                <x-ui.action wire:click="toggleAdmin({{ $u->id }})" tone="accent">
                                    {{ $u->is_admin ? 'Revoke admin' : 'Make admin' }}
                                </x-ui.action>
                                <x-ui.action wire:click="resetPassword({{ $u->id }})"
                                             wire:confirm="Generate a new password for {{ $u->email }}? The old one stops working immediately.">Reset password</x-ui.action>
                                <x-ui.action wire:click="deleteUser({{ $u->id }})" wire:confirm="Delete user {{ $u->email }}?" tone="danger">Delete</x-ui.action>
                            </div>
                        @else
                            <span class="font-mono text-[11px] text-mute-4">you</span>
                        @endif
                    </x-ui.td>
                </x-ui.tr>
            @endforeach
        </x-ui.table>
    </x-ui.panel>
</div>

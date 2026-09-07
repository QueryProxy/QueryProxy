<div>
    <x-ui.page-header title="Teams" subtitle="Teams isolate connections, requests and audit trails.">
        <x-slot:actions>
            <x-ui.btn :href="route('admin.users')" icon="users">Manage users</x-ui.btn>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.panel padded class="mb-3.5">
        <form wire:submit="createTeam" class="flex items-end gap-2.5">
            <x-ui.field label="New team name" class="flex-1" :error="$errors->first('name')">
                <x-ui.input type="text" wire:model="name" placeholder="e.g. Payments Squad" />
            </x-ui.field>
            <x-ui.btn type="submit" variant="primary" icon="plus">Create team</x-ui.btn>
        </form>
    </x-ui.panel>

    <x-ui.panel>
        <x-ui.table>
            <x-slot:head>
                <tr>
                    <x-ui.th>Team</x-ui.th>
                    <x-ui.th class="w-48">Slug</x-ui.th>
                    <x-ui.th class="w-24">Members</x-ui.th>
                    <x-ui.th class="w-28">Created</x-ui.th>
                    <x-ui.th class="w-36" align="right"></x-ui.th>
                </tr>
            </x-slot:head>

            @forelse ($teams as $team)
                <x-ui.tr>
                    <x-ui.td class="text-ink-2">{{ $team->name }}</x-ui.td>
                    <x-ui.td mono muted>{{ $team->slug }}</x-ui.td>
                    <x-ui.td mono>{{ $team->users_count }}</x-ui.td>
                    <x-ui.td mono muted>{{ $team->created_at->format('Y-m-d') }}</x-ui.td>
                    <x-ui.td align="right" nowrap>
                        <div class="flex items-center justify-end gap-2.5">
                            <x-ui.action :href="route('admin.teams.members', $team)" tone="accent">Members</x-ui.action>
                            <x-ui.action wire:click="deleteTeam({{ $team->id }})"
                                         wire:confirm="Delete team {{ $team->name }}? Connections and requests in it will be removed."
                                         tone="danger">Delete</x-ui.action>
                        </div>
                    </x-ui.td>
                </x-ui.tr>
            @empty
                <x-ui.empty :colspan="5">No teams yet — create the first one above.</x-ui.empty>
            @endforelse
        </x-ui.table>
    </x-ui.panel>
</div>

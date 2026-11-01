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
                    <x-ui.th class="w-24">Secret paths</x-ui.th>
                    <x-ui.th class="w-56" align="right"></x-ui.th>
                </tr>
            </x-slot:head>

            @forelse ($teams as $team)
                <x-ui.tr>
                    <x-ui.td class="text-ink-2">{{ $team->name }}</x-ui.td>
                    <x-ui.td mono muted>{{ $team->slug }}</x-ui.td>
                    <x-ui.td mono>{{ $team->users_count }}</x-ui.td>
                    <x-ui.td mono muted>{{ $team->created_at->format('Y-m-d') }}</x-ui.td>
                    <x-ui.td>
                        @php($prefixCount = count($team->secretReferencePrefixes()))
                        @if ($prefixCount === 0)
                            <x-ui.badge>None</x-ui.badge>
                        @else
                            <x-ui.badge tone="accent">{{ $prefixCount }} {{ $prefixCount === 1 ? 'path' : 'paths' }}</x-ui.badge>
                        @endif
                    </x-ui.td>
                    <x-ui.td align="right" nowrap>
                        <div class="flex items-center justify-end gap-2.5">
                            <x-ui.action wire:click="togglePrefixes({{ $team->id }})" tone="accent"
                                         aria-expanded="{{ $prefixesForId === $team->id ? 'true' : 'false' }}"
                                         aria-controls="team-prefixes-{{ $team->id }}">Secret paths</x-ui.action>
                            <x-ui.action :href="route('admin.teams.members', $team)" tone="accent">Members</x-ui.action>
                            <x-ui.action wire:click="deleteTeam({{ $team->id }})"
                                         wire:confirm="Delete team {{ $team->name }}? Connections and requests in it will be removed."
                                         tone="danger">Delete</x-ui.action>
                        </div>
                    </x-ui.td>
                </x-ui.tr>

                @if ($prefixesForId === $team->id)
                    <tr class="bg-canvas">
                        <td colspan="6" id="team-prefixes-{{ $team->id }}" class="border-b border-line px-3.5 py-3.5">
                            <p class="eyebrow mb-2.5">Secret paths of “{{ $team->name }}”</p>

                            <p class="mb-3 max-w-3xl text-[12.5px] text-mute-4">
                                QueryProxy reads external secrets with one identity for the whole instance, so each team may only reference secrets under the paths listed here.
                                A connection's reference must fall under one of them, when it is saved and again each time it is read. A team without a path cannot use secret references at all: its connection form offers stored passwords only.
                            </p>

                            @if ($openPrefixes === [])
                                <x-ui.alert tone="pending" icon="alert" class="mb-3">
                                    No path yet, so this team cannot use secret references. Connections that already hold one fail until a path covering it is added.
                                </x-ui.alert>
                            @else
                                <ul class="mb-3 flex flex-col">
                                    @foreach ($openPrefixes as $prefix)
                                        <li wire:key="prefix-{{ $team->id }}-{{ md5($prefix) }}"
                                            class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-t border-line py-1.5 first:border-t-0">
                                            <code class="min-w-0 break-all font-mono text-[12px] text-ink-3">{{ $prefix }}</code>
                                            <x-ui.action wire:click="removePrefix({{ $team->id }}, @js($prefix))"
                                                         wire:confirm="Remove {{ $prefix }}? Connections of {{ $team->name }} with a reference under it stop working."
                                                         aria-label="Remove the path {{ $prefix }}" tone="danger">Remove</x-ui.action>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            @foreach ($overlaps as $prefix => $names)
                                <x-ui.alert tone="pending" icon="alert" class="mb-3">
                                    <code class="font-mono break-all">{{ $prefix }}</code> overlaps with a path of {{ implode(', ', $names) }}. A secret under the shared part can be referenced by both teams.
                                </x-ui.alert>
                            @endforeach

                            <form wire:submit="addPrefix({{ $team->id }})" class="flex flex-wrap items-end gap-2.5">
                                <x-ui.field label="Add a path" for="new-prefix-{{ $team->id }}" class="min-w-[16rem] flex-1" :error="$errors->first('newPrefix')">
                                    <x-ui.input id="new-prefix-{{ $team->id }}" type="text" wire:model="newPrefix" class="font-mono" autocomplete="off" spellcheck="false"
                                                placeholder="vault://secret/teams/payments" aria-describedby="new-prefix-help-{{ $team->id }}" />
                                </x-ui.field>
                                <x-ui.btn type="submit" icon="plus">Add path</x-ui.btn>
                            </form>
                            <p id="new-prefix-help-{{ $team->id }}" class="mt-1.5 text-[12px] text-mute-4">
                                Forms: <code class="font-mono">vault://&lt;mount&gt;/&lt;path&gt;</code>, <code class="font-mono">aws-sm://&lt;name-prefix&gt;</code>, <code class="font-mono">azure-kv://&lt;vault-name&gt;[/&lt;secret-prefix&gt;]</code>.
                                A path covers whole segments below it: <code class="font-mono">vault://secret/teams/pay</code> does not cover <code class="font-mono">vault://secret/teams/payments</code>. No wildcards, dot segments or <code class="font-mono">#key</code>. Changes are written to the audit log.
                            </p>
                        </td>
                    </tr>
                @endif
            @empty
                <x-ui.empty :colspan="6">No teams yet — create the first one above.</x-ui.empty>
            @endforelse
        </x-ui.table>
    </x-ui.panel>
</div>

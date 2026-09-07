<div>
    <x-ui.page-header
        title="Connections"
        subtitle="Target databases this team can reach through QueryProxy. Credentials are encrypted at rest and are never rendered back into the browser.">
        <x-slot:actions>
            <x-ui.btn wire:click="openCreate" variant="primary" icon="plus">New connection</x-ui.btn>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($testResult)
        <x-ui.alert :tone="$testResult['ok'] ? 'ok' : 'danger'" :icon="$testResult['ok'] ? 'check' : 'x'" class="mb-3.5">
            @if ($testResult['ok'])
                Connection OK — {{ $testResult['latency_ms'] }} ms
            @else
                Connection failed: {{ $testResult['error'] }}
            @endif
        </x-ui.alert>
    @endif

    @if ($showForm)
        <x-ui.panel padded class="mb-3.5 border-accent-line">
            <h2 class="mb-3.5 font-display text-[13px] font-semibold text-ink">{{ $editingId ? 'Edit connection' : 'New connection' }}</h2>

            <form wire:submit="save" class="grid grid-cols-1 gap-3.5 sm:grid-cols-3">
                <x-ui.field label="Name" :error="$errors->first('name')">
                    <x-ui.input type="text" wire:model="name" placeholder="e.g. orders-prod" />
                </x-ui.field>

                <x-ui.field label="Driver">
                    <x-ui.select wire:model.live="driver">
                        @foreach ($drivers as $d)
                            <option value="{{ $d->value }}">{{ $d->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <div></div>

                @if ($driver !== 'sqlite')
                    <x-ui.field label="Host" :error="$errors->first('host')">
                        <x-ui.input type="text" wire:model="host" />
                    </x-ui.field>

                    <x-ui.field label="Port" hint="blank = default" :error="$errors->first('port')">
                        <x-ui.input type="number" wire:model="port" />
                    </x-ui.field>
                @endif

                <x-ui.field :label="$driver === 'sqlite' ? 'Database file path' : 'Database'" :error="$errors->first('database')">
                    <x-ui.input type="text" wire:model="database" class="font-mono" />
                </x-ui.field>

                @if ($driver !== 'sqlite')
                    <x-ui.field label="Username" :error="$errors->first('username')">
                        <x-ui.input type="text" wire:model="username" autocomplete="off" />
                    </x-ui.field>

                    <x-ui.field label="Password" :hint="$editingId ? 'blank = keep current' : null" :error="$errors->first('password')">
                        <x-ui.input type="password" wire:model="password" autocomplete="new-password" />
                    </x-ui.field>
                @endif

                <div class="flex items-end gap-2 sm:col-span-3">
                    <x-ui.btn type="submit" variant="primary">{{ $editingId ? 'Save changes' : 'Create connection' }}</x-ui.btn>
                    <x-ui.btn type="button" wire:click="$set('showForm', false)">Cancel</x-ui.btn>
                </div>
            </form>
        </x-ui.panel>
    @endif

    <x-ui.panel>
        <x-ui.table>
            <x-slot:head>
                <tr>
                    <x-ui.th class="w-48">Name</x-ui.th>
                    <x-ui.th class="w-24">Driver</x-ui.th>
                    <x-ui.th>Database</x-ui.th>
                    <x-ui.th class="w-28">Developers</x-ui.th>
                    <x-ui.th class="w-52" align="right">Actions</x-ui.th>
                </tr>
            </x-slot:head>

            @forelse ($connections as $connection)
                <x-ui.tr :hover="false">
                    <x-ui.td mono class="text-ink-2">{{ $connection->name }}</x-ui.td>
                    <x-ui.td><x-ui.badge>{{ $connection->driver->label() }}</x-ui.badge></x-ui.td>
                    <x-ui.td mono muted truncate>{{ $connection->database }}</x-ui.td>
                    <x-ui.td mono muted>{{ $connection->grantedUsers->count() }} granted</x-ui.td>
                    <x-ui.td align="right" nowrap>
                        <div class="flex items-center justify-end gap-2.5">
                            <x-ui.action wire:click="testConnection({{ $connection->id }})" wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="testConnection({{ $connection->id }})">Test</span>
                                <span wire:loading wire:target="testConnection({{ $connection->id }})">Testing…</span>
                            </x-ui.action>
                            <x-ui.action wire:click="toggleGrants({{ $connection->id }})" tone="accent">Grants</x-ui.action>
                            <x-ui.action wire:click="openEdit({{ $connection->id }})" tone="accent">Edit</x-ui.action>
                            <x-ui.action wire:click="deleteConnection({{ $connection->id }})"
                                         wire:confirm="Delete connection {{ $connection->name }}?" tone="danger">Delete</x-ui.action>
                        </div>
                    </x-ui.td>
                </x-ui.tr>

                @if ($grantsForId === $connection->id)
                    <tr class="bg-canvas">
                        <td colspan="5" class="border-b border-line px-3.5 py-3.5">
                            <p class="eyebrow mb-2.5">Developer access to “{{ $connection->name }}”</p>
                            <div class="flex flex-wrap gap-x-5 gap-y-2">
                                @forelse ($developers as $developer)
                                    <label class="inline-flex items-center gap-2 text-[12.5px] text-ink-3">
                                        <x-ui.checkbox wire:change="toggleGrant({{ $connection->id }}, {{ $developer->id }})"
                                                       @checked($connection->grantedUsers->contains($developer)) />
                                        {{ $developer->name }}
                                        <span class="font-mono text-[11px] text-mute-4">{{ $developer->email }}</span>
                                    </label>
                                @empty
                                    <p class="text-[12.5px] text-mute-4">This team has no developers yet.</p>
                                @endforelse
                            </div>
                        </td>
                    </tr>
                @endif
            @empty
                <x-ui.empty :colspan="5">No connections yet.</x-ui.empty>
            @endforelse
        </x-ui.table>
    </x-ui.panel>
</div>

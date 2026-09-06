<div>
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Connections</h1>
            <p class="mt-1 text-sm text-slate-500">Target databases this team can query through QueryProxy. Credentials are encrypted at rest.</p>
        </div>
        <button wire:click="openCreate" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
            New Connection
        </button>
    </div>

    @if($testResult)
        <div class="mb-4 rounded-md border px-4 py-3 text-sm {{ $testResult['ok'] ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800' }}">
            @if($testResult['ok'])
                Connection OK — {{ $testResult['latency_ms'] }} ms
            @else
                Connection failed: {{ $testResult['error'] }}
            @endif
        </div>
    @endif

    @if($showForm)
        <div class="mb-6 rounded-xl border border-indigo-200 bg-white p-5 shadow-sm">
            <h2 class="mb-4 text-lg font-semibold">{{ $editingId ? 'Edit Connection' : 'New Connection' }}</h2>
            <form wire:submit="save" class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Name</label>
                    <input type="text" wire:model="name" placeholder="e.g. Orders (prod replica)" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                    @error('name') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Driver</label>
                    <select wire:model.live="driver" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                        @foreach($drivers as $d)
                            <option value="{{ $d->value }}">{{ $d->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div></div>
                @if($driver !== 'sqlite')
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Host</label>
                        <input type="text" wire:model="host" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                        @error('host') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Port <span class="text-slate-400">(blank = default)</span></label>
                        <input type="number" wire:model="port" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                        @error('port') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>
                @endif
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">{{ $driver === 'sqlite' ? 'Database file path' : 'Database' }}</label>
                    <input type="text" wire:model="database" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                    @error('database') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                </div>
                @if($driver !== 'sqlite')
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Username</label>
                        <input type="text" wire:model="username" autocomplete="off" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                        @error('username') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">
                            Password @if($editingId)<span class="text-slate-400">(blank = keep current)</span>@endif
                        </label>
                        <input type="password" wire:model="password" autocomplete="new-password" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                        @error('password') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>
                @endif
                <div class="flex items-end gap-2 sm:col-span-3">
                    <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                        {{ $editingId ? 'Save Changes' : 'Create Connection' }}
                    </button>
                    <button type="button" wire:click="$set('showForm', false)" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    @endif

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3">Name</th>
                    <th class="px-4 py-3">Driver</th>
                    <th class="px-4 py-3">Database</th>
                    <th class="px-4 py-3">Granted Devs</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($connections as $connection)
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $connection->name }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded bg-slate-100 px-1.5 py-0.5 text-xs font-medium text-slate-600">{{ $connection->driver->label() }}</span>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-slate-500">{{ $connection->database }}</td>
                        <td class="px-4 py-3">{{ $connection->grantedUsers->count() }}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <button wire:click="testConnection({{ $connection->id }})" wire:loading.attr="disabled" class="font-medium text-slate-600 hover:text-slate-500">
                                <span wire:loading.remove wire:target="testConnection({{ $connection->id }})">Test</span>
                                <span wire:loading wire:target="testConnection({{ $connection->id }})">Testing…</span>
                            </button>
                            <button wire:click="toggleGrants({{ $connection->id }})" class="ml-3 font-medium text-indigo-600 hover:text-indigo-500">Grants</button>
                            <button wire:click="openEdit({{ $connection->id }})" class="ml-3 font-medium text-indigo-600 hover:text-indigo-500">Edit</button>
                            <button wire:click="deleteConnection({{ $connection->id }})"
                                    wire:confirm="Delete connection {{ $connection->name }}?"
                                    class="ml-3 font-medium text-rose-600 hover:text-rose-500">Delete</button>
                        </td>
                    </tr>
                    @if($grantsForId === $connection->id)
                        <tr class="bg-slate-50">
                            <td colspan="5" class="px-4 py-4">
                                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Developer access to “{{ $connection->name }}”</p>
                                @forelse($developers as $developer)
                                    <label class="mr-6 inline-flex items-center gap-2 text-sm">
                                        <input type="checkbox"
                                               wire:change="toggleGrant({{ $connection->id }}, {{ $developer->id }})"
                                               @checked($connection->grantedUsers->contains($developer))
                                               class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                        {{ $developer->name }} <span class="text-slate-400">({{ $developer->email }})</span>
                                    </label>
                                @empty
                                    <p class="text-sm text-slate-400">This team has no developers yet.</p>
                                @endforelse
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-slate-400">No connections yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

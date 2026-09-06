<div>
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Masking Rules</h1>
            <p class="mt-1 text-sm text-slate-500">PII is masked while results are written — unmasked data never reaches the result store.</p>
        </div>
        <div class="flex gap-2">
            <button wire:click="addDefaults" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                Add Default Rules
            </button>
            <button wire:click="openCreate" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                New Rule
            </button>
        </div>
    </div>

    @if($showForm)
        <div class="mb-6 rounded-xl border border-indigo-200 bg-white p-5 shadow-sm">
            <h2 class="mb-4 text-lg font-semibold">{{ $editingId ? 'Edit Rule' : 'New Rule' }}</h2>
            <form wire:submit="save" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Name</label>
                    <input type="text" wire:model="name" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                    @error('name') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Match type</label>
                    <select wire:model.live="matchType" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                        @foreach($matchTypes as $t)
                            <option value="{{ $t->value }}">{{ $t->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">
                        Pattern
                        <span class="text-slate-400">{{ $matchType === 'column' ? '(wildcards, e.g. *email* — use | for alternatives)' : '(PCRE regex, e.g. /\d{16}/)' }}</span>
                    </label>
                    <input type="text" wire:model="pattern" class="w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-sm">
                    @error('pattern') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Strategy</label>
                    <select wire:model="strategy" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                        @foreach($strategies as $s)
                            <option value="{{ $s->value }}">{{ $s->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Scope</label>
                    <select wire:model="connectionId" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                        <option value="">Whole team (all connections)</option>
                        @foreach($connections as $connection)
                            <option value="{{ $connection->id }}">Only: {{ $connection->name }}</option>
                        @endforeach
                    </select>
                    @error('connectionId') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                        {{ $editingId ? 'Save Changes' : 'Create Rule' }}
                    </button>
                    <button type="button" wire:click="$set('showForm', false)" class="rounded-md border border-slate-300 px-4 py-2 text-sm">Cancel</button>
                </div>
            </form>
        </div>
    @endif

    <div class="mb-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3">Rule</th>
                    <th class="px-4 py-3">Match</th>
                    <th class="px-4 py-3">Pattern</th>
                    <th class="px-4 py-3">Strategy</th>
                    <th class="px-4 py-3">Scope</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($rules as $rule)
                    <tr class="{{ $rule->enabled ? '' : 'opacity-50' }}">
                        <td class="px-4 py-3 font-medium">{{ $rule->name }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ $rule->match_type->value }}</td>
                        <td class="max-w-xs truncate px-4 py-3 font-mono text-xs text-slate-600">{{ $rule->pattern }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ $rule->strategy->value }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ $rule->connection?->name ?? 'Team-wide' }}</td>
                        <td class="px-4 py-3">
                            <button wire:click="toggle({{ $rule->id }})"
                                    class="rounded px-1.5 py-0.5 text-xs font-medium {{ $rule->enabled ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">
                                {{ $rule->enabled ? 'Enabled' : 'Disabled' }}
                            </button>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button wire:click="openEdit({{ $rule->id }})" class="font-medium text-indigo-600 hover:text-indigo-500">Edit</button>
                            <button wire:click="deleteRule({{ $rule->id }})" wire:confirm="Delete rule {{ $rule->name }}?"
                                    class="ml-3 font-medium text-rose-600 hover:text-rose-500">Delete</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-slate-400">No masking rules yet — start with the defaults.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <h3 class="mb-3 text-sm font-semibold text-slate-700">Playground — test your enabled rules</h3>
        <form wire:submit="preview" class="flex flex-wrap items-end gap-3">
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Column name</label>
                <input type="text" wire:model="sampleColumn" class="rounded-md border border-slate-300 px-3 py-2 font-mono text-sm">
            </div>
            <div class="min-w-64 flex-1">
                <label class="mb-1 block text-sm font-medium text-slate-700">Sample value</label>
                <input type="text" wire:model="sampleValue" class="w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-sm">
            </div>
            <button type="submit" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Preview</button>
            @if($sampleResult !== null)
                <div class="rounded-md bg-slate-100 px-3 py-2 font-mono text-sm">→ {{ $sampleResult }}</div>
            @endif
        </form>
    </div>
</div>

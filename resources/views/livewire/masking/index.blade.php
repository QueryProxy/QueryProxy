<div>
    <x-ui.page-header
        title="Masking Rules"
        subtitle="PII is masked while results are written — unmasked data never reaches the result store.">
        <x-slot:actions>
            <x-ui.btn wire:click="addDefaults">Add default rules</x-ui.btn>
            <x-ui.btn wire:click="openCreate" variant="primary" icon="plus">New rule</x-ui.btn>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($showForm)
        <x-ui.panel padded class="mb-3.5 border-accent-line">
            <h2 class="mb-3.5 font-display text-[13px] font-semibold text-ink">{{ $editingId ? 'Edit rule' : 'New rule' }}</h2>

            <form wire:submit="save" class="grid grid-cols-1 gap-3.5 sm:grid-cols-2">
                <x-ui.field label="Name" :error="$errors->first('name')">
                    <x-ui.input type="text" wire:model="name" />
                </x-ui.field>

                <x-ui.field label="Match type">
                    <x-ui.select wire:model.live="matchType">
                        @foreach ($matchTypes as $t)
                            <option value="{{ $t->value }}">{{ $t->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                @php
                    $patternHint = $matchType === 'column'
                        ? 'wildcards, e.g. *email* — use | for alternatives'
                        : 'PCRE regex, e.g. /\d{16}/';
                @endphp
                <x-ui.field label="Pattern" :hint="$patternHint" :error="$errors->first('pattern')">
                    <x-ui.input type="text" wire:model="pattern" class="font-mono" />
                </x-ui.field>

                <x-ui.field label="Strategy">
                    <x-ui.select wire:model="strategy">
                        @foreach ($strategies as $s)
                            <option value="{{ $s->value }}">{{ $s->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Scope" :error="$errors->first('connectionId')">
                    <x-ui.select wire:model="connectionId">
                        <option value="">Whole team (all connections)</option>
                        @foreach ($connections as $connection)
                            <option value="{{ $connection->id }}">Only: {{ $connection->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <div class="flex items-end gap-2">
                    <x-ui.btn type="submit" variant="primary">{{ $editingId ? 'Save changes' : 'Create rule' }}</x-ui.btn>
                    <x-ui.btn type="button" wire:click="$set('showForm', false)">Cancel</x-ui.btn>
                </div>
            </form>
        </x-ui.panel>
    @endif

    <x-ui.panel class="mb-3.5">
        <x-ui.table>
            <x-slot:head>
                <tr>
                    <x-ui.th class="w-48">Rule</x-ui.th>
                    <x-ui.th class="w-24">Match</x-ui.th>
                    <x-ui.th>Pattern</x-ui.th>
                    <x-ui.th class="w-32">Strategy</x-ui.th>
                    <x-ui.th class="w-40">Scope</x-ui.th>
                    <x-ui.th class="w-24">Status</x-ui.th>
                    <x-ui.th class="w-28" align="right"></x-ui.th>
                </tr>
            </x-slot:head>

            @forelse ($rules as $rule)
                <x-ui.tr :hover="false" class="{{ $rule->enabled ? '' : 'opacity-50' }}">
                    <x-ui.td class="text-ink-2">{{ $rule->name }}</x-ui.td>
                    <x-ui.td mono muted>{{ $rule->match_type->value }}</x-ui.td>
                    <x-ui.td mono truncate>{{ $rule->pattern }}</x-ui.td>
                    <x-ui.td mono muted>{{ $rule->strategy->value }}</x-ui.td>
                    <x-ui.td muted>{{ $rule->connection?->name ?? 'Team-wide' }}</x-ui.td>
                    <x-ui.td>
                        <button type="button" wire:click="toggle({{ $rule->id }})">
                            <x-ui.badge :tone="$rule->enabled ? 'ok' : 'neutral'">{{ $rule->enabled ? 'Enabled' : 'Disabled' }}</x-ui.badge>
                        </button>
                    </x-ui.td>
                    <x-ui.td align="right" nowrap>
                        <div class="flex items-center justify-end gap-2.5">
                            <x-ui.action wire:click="openEdit({{ $rule->id }})" tone="accent">Edit</x-ui.action>
                            <x-ui.action wire:click="deleteRule({{ $rule->id }})" wire:confirm="Delete rule {{ $rule->name }}?" tone="danger">Delete</x-ui.action>
                        </div>
                    </x-ui.td>
                </x-ui.tr>
            @empty
                <x-ui.empty :colspan="7">No masking rules yet — start with the defaults.</x-ui.empty>
            @endforelse
        </x-ui.table>
    </x-ui.panel>

    <x-ui.panel title="Playground — test your enabled rules" padded>
        <form wire:submit="preview" class="flex flex-wrap items-end gap-2.5">
            <x-ui.field label="Column name" class="w-56">
                <x-ui.input type="text" wire:model="sampleColumn" class="font-mono" />
            </x-ui.field>

            <x-ui.field label="Sample value" class="min-w-64 flex-1">
                <x-ui.input type="text" wire:model="sampleValue" class="font-mono" />
            </x-ui.field>

            <x-ui.btn type="submit">Preview</x-ui.btn>

            @if ($sampleResult !== null)
                <div class="flex h-[30px] items-center rounded-control border border-line bg-canvas px-2.5 font-mono text-[12.5px] text-ok">
                    → {{ $sampleResult }}
                </div>
            @endif
        </form>
    </x-ui.panel>
</div>

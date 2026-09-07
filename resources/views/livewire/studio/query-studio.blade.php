<div>
    <x-ui.page-header
        title="Query Studio"
        subtitle="Write SQL against a connection you have been granted, run it through the guards, and submit it for approval. Nothing reaches the database before a DBA approves it.">
        <x-slot:actions>
            <x-ui.chip static>
                <x-ui.icon name="lock" :size="14" />
                Guards enforced
            </x-ui.chip>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($connections->isEmpty())
        <x-ui.alert tone="pending" icon="lock">
            You don't have access to any connection in this team yet. Ask a DBA to grant you one.
        </x-ui.alert>
    @else
        <div class="flex flex-col gap-3.5">
            <x-ui.panel padded>
                <div class="grid grid-cols-1 items-end gap-3.5 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]">
                    <x-ui.field label="Connection" :error="$errors->first('connectionId')">
                        <x-ui.select wire:model="connectionId">
                            <option value="">— choose a connection —</option>
                            @foreach ($connections as $connection)
                                <option value="{{ $connection->id }}">{{ $connection->name }} ({{ $connection->driver->label() }})</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>

                    <x-ui.field label="Title" hint="optional" :error="$errors->first('title')">
                        <x-ui.input type="text" wire:model="title" placeholder="e.g. Fix duplicated order rows" />
                    </x-ui.field>

                    <div class="flex flex-wrap gap-1.5 pb-px">
                        <x-ui.chip static>WHERE required</x-ui.chip>
                        <x-ui.chip static>LIMIT {{ number_format(config('queryproxy.select_default_limit')) }}</x-ui.chip>
                        <x-ui.chip static>cap {{ number_format(config('queryproxy.select_hard_limit')) }}</x-ui.chip>
                    </div>
                </div>
            </x-ui.panel>

            <x-ui.panel title="SQL">
                <div wire:ignore
                     x-data
                     x-init="window.createSqlEditor($refs.editor, $wire.sql, (value) => $wire.$set('sql', value, false))"
                     class="bg-panel">
                    <div x-ref="editor"></div>
                </div>
            </x-ui.panel>
            @error('sql') <p class="-mt-1 text-[12px] text-danger">{{ $message }}</p> @enderror

            <div class="flex items-center gap-2.5">
                <x-ui.btn wire:click="validateSql" wire:loading.attr="disabled" icon="play">Validate</x-ui.btn>
                <x-ui.btn wire:click="submit" wire:loading.attr="disabled" variant="primary" icon="arrow-right">Submit for approval</x-ui.btn>
                <span wire:loading class="font-mono text-[11px] text-mute-4">Working…</span>
            </div>

            @if ($violations)
                <x-ui.panel class="border-danger-line">
                    <x-slot:header>
                        <x-ui.icon name="x" :size="15" class="text-danger" />
                        <span class="font-display text-[11.5px] font-semibold uppercase tracking-[0.07em] text-danger">Blocked by guards</span>
                        <span class="ml-auto font-mono text-[11px] text-mute-3">{{ count($violations) }} {{ Str::plural('violation', count($violations)) }}</span>
                    </x-slot:header>

                    <ul class="flex flex-col gap-1.5 p-3.5">
                        @foreach ($violations as $violation)
                            <li class="flex items-start gap-2.5">
                                <x-ui.icon name="x" :size="14" class="mt-0.5 text-danger" />
                                <span class="text-[12px] leading-[1.5] text-ink-3">{{ $violation }}</span>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.panel>
            @endif

            @if ($validated && $preparedPreview !== null)
                <x-ui.panel class="border-ok-line">
                    <x-slot:header>
                        <x-ui.icon name="check" :size="15" class="text-ok" />
                        <span class="font-display text-[11.5px] font-semibold uppercase tracking-[0.07em] text-ok">Guards passed</span>
                        <span class="ml-auto font-mono text-[11px] text-mute-3">will run as {{ $previewType }}</span>
                    </x-slot:header>

                    <div class="p-3.5">
                        <div class="eyebrow mb-1.5">Prepared statement</div>
                        <x-ui.code>{{ $preparedPreview }}</x-ui.code>
                    </div>
                </x-ui.panel>
            @endif
        </div>
    @endif
</div>

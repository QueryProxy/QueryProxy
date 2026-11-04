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

    @if ($hasNoEnabledMaskingRules)
        <x-ui.alert tone="danger" icon="alert" class="mb-3.5">
            @if ($hasNoMaskingRules)
                This team has no masking rules — query results are returned unmasked. Ask a DBA to add masking rules.
            @else
                This team has no enabled masking rules — query results are returned unmasked. Ask a DBA to enable masking rules.
            @endif
        </x-ui.alert>
    @endif

    @if ($connections->isEmpty())
        <x-ui.alert tone="pending" icon="lock">
            You don't have access to any connection in this team yet. Ask a DBA to grant you one.
        </x-ui.alert>
    @else
        <div class="flex flex-col gap-3.5">
            <x-ui.panel padded>
                <div class="grid grid-cols-1 items-end gap-3.5 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]">
                    <x-ui.field label="Connection" :error="$errors->first('connectionId')">
                        <x-ui.select wire:model.live="connectionId">
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

            <x-ui.panel padded>
                <x-ui.field label="Reason" hint="optional — shown to the DBA who reviews this request" :error="$errors->first('note')">
                    <x-ui.textarea wire:model="note" rows="2" placeholder="Why is this change needed? Link a ticket if there is one." />
                </x-ui.field>
            </x-ui.panel>

            <div wire:ignore
                 x-data="schemaExplorer(@js(['connections' => $schemaConnections]))"
                 @keydown.escape.window="if (isDrawer && shown) close()"
                 class="relative lg:grid lg:items-start lg:gap-3.5"
                 :class="shown ? 'lg:grid-cols-[280px_minmax(0,1fr)]' : 'lg:grid-cols-1'">

                <div x-show="shown && isDrawer" x-cloak x-transition.opacity
                     @click="close()"
                     class="fixed inset-0 z-40 bg-canvas/70 lg:hidden"
                     aria-hidden="true"></div>

                <aside id="schema-panel"
                       x-show="shown" x-cloak
                       x-trap.noscroll.noreturn="isDrawer && shown"
                       aria-label="Schema explorer"
                       :role="isDrawer ? 'dialog' : null"
                       :aria-modal="isDrawer ? 'true' : null"
                       class="fixed inset-y-0 left-0 z-50 flex w-[min(340px,88vw)] flex-col border-r border-line bg-panel shadow-xl lg:static lg:z-auto lg:max-h-[460px] lg:w-auto lg:overflow-hidden lg:rounded-panel lg:border lg:shadow-none">
                    <div class="flex items-center gap-2 border-b border-line bg-header px-3 py-2.5">
                        <span class="font-display text-[11.5px] font-semibold uppercase tracking-[0.07em] text-ink-4">Schema</span>
                        <span x-show="loadedLabel" x-text="loadedLabel" class="font-mono text-[10.5px] text-mute-4"></span>
                        <div class="ml-auto flex items-center gap-1">
                            <x-ui.btn variant="ghost" size="sm" icon="refresh"
                                      x-on:click="load(true)"
                                      x-bind:disabled="!currentConnection() || status === 'loading' || refreshing"
                                      aria-label="Refresh schema"
                                      title="Refresh schema from the database"></x-ui.btn>
                            <x-ui.btn variant="ghost" size="sm" icon="x"
                                      class="lg:hidden"
                                      x-on:click="close()"
                                      aria-label="Close schema explorer"></x-ui.btn>
                        </div>
                    </div>

                    <div class="relative border-b border-line p-2.5">
                        <label for="schema-filter" class="sr-only">Filter tables and columns</label>
                        <x-ui.icon name="search" :size="14" class="pointer-events-none absolute top-1/2 left-5 -translate-y-1/2 text-mute-4" />
                        <x-ui.input id="schema-filter" type="search" x-ref="search" x-model="query"
                                    x-on:input.debounce.120ms="rebuild()"
                                    x-on:keydown.escape="if (query !== '') { $event.stopPropagation(); query = ''; rebuild(); }"
                                    x-bind:disabled="status !== 'ready'"
                                    placeholder="Filter tables and columns"
                                    autocomplete="off"
                                    class="pl-8" />
                    </div>

                    <div x-show="error && status === 'ready'" role="alert"
                         class="border-b border-danger-line bg-danger-bg px-3 py-2 text-[12px] leading-[1.45] text-danger"
                         x-text="error"></div>
                    <div x-show="truncated && status === 'ready'"
                         class="border-b border-pending-line bg-pending-bg px-3 py-2 text-[12px] leading-[1.45] text-pending">
                        This schema is very large, so only part of it is listed. Completion covers the same part.
                    </div>

                    <div class="min-h-[120px] flex-1 overflow-y-auto" tabindex="-1">
                        <p x-show="status === 'idle'" class="px-3.5 py-5 text-[12px] leading-[1.5] text-mute-3">
                            Choose a connection to browse its tables and columns.
                        </p>
                        <p x-show="status === 'loading'" role="status" class="px-3.5 py-5 font-mono text-[11.5px] text-mute-3">
                            Loading schema…
                        </p>
                        <div x-show="status === 'failed'" role="alert" class="flex flex-col items-start gap-2.5 px-3.5 py-5">
                            <p class="text-[12px] leading-[1.5] text-danger" x-text="error"></p>
                            <x-ui.btn size="sm" icon="refresh" x-on:click="load()">Try again</x-ui.btn>
                        </div>
                        <p x-show="status === 'ready' && !hasObjects" class="px-3.5 py-5 text-[12px] leading-[1.5] text-mute-3">
                            This connection has no tables or views you can see.
                        </p>
                        <p x-show="status === 'ready' && hasObjects && rows.length === 0" class="px-3.5 py-5 text-[12px] leading-[1.5] text-mute-3">
                            Nothing matches “<span x-text="query"></span>”.
                        </p>

                        <div x-ref="tree" role="tree" aria-label="Tables and columns"
                             x-show="status === 'ready' && rows.length > 0"
                             class="py-1">
                            <template x-for="row in rows" :key="row.key">
                                <div role="treeitem"
                                     :data-key="row.key"
                                     :aria-level="row.level"
                                     :aria-expanded="row.expandable ? String(row.open) : null"
                                     :aria-label="rowLabel(row)"
                                     :tabindex="row.key === activeKey ? 0 : -1"
                                     @focus="activeKey = row.key; description = describe(row)"
                                     @click="activate(row)"
                                     @keydown="onKeydown($event, row)"
                                     class="group flex min-h-8 cursor-pointer items-center gap-1.5 pr-3 text-[12.5px] text-ink-3 outline-none hover:bg-raised focus-visible:bg-raised focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-accent/40"
                                     :style="{ paddingLeft: (row.level * 12 - 2) + 'px' }">
                                    <span class="flex size-4 shrink-0 items-center justify-center text-mute-3"
                                          @click.stop="toggle(row)" aria-hidden="true">
                                        <x-ui.icon name="chevron-right" :size="12" x-show="row.expandable" class="transition-transform" x-bind:class="row.open && 'rotate-90'" />
                                    </span>
                                    <x-ui.icon name="database" :size="13" x-show="row.kind === 'schema'" class="text-mute-3" />
                                    <span class="min-w-0 flex-1 truncate" :class="row.kind === 'column' ? 'font-mono text-[12px] text-ink-3' : 'font-medium text-ink-2'" x-text="row.name" :title="row.name"></span>
                                    <span x-show="row.kind === 'table' && row.type === 'view'" class="rounded-badge border border-line px-1 font-mono text-[10px] text-mute-3">view</span>
                                    <span x-show="row.kind === 'schema' || row.kind === 'table'" class="font-mono text-[10.5px] text-mute-4" x-text="row.count" aria-hidden="true"></span>
                                    <span x-show="row.kind === 'column'" class="max-w-[40%] truncate font-mono text-[10.5px] text-mute-3" x-text="row.type" aria-hidden="true"></span>
                                    <span x-show="row.masked" class="inline-flex shrink-0 items-center text-pending"
                                          title="Masked: values are hidden in query results">
                                        <x-ui.icon name="lock" :size="12" />
                                    </span>
                                </div>
                            </template>
                        </div>
                        <p x-show="status === 'ready' && moreRows > 0" role="status" class="px-3.5 py-2 text-[12px] leading-[1.45] text-mute-3">
                            <span x-text="moreRows"></span> more <span x-text="moreRows === 1 ? 'item' : 'items'"></span> not shown. Narrow the filter to see them.
                        </p>
                    </div>

                    <div class="min-h-9 border-t border-line px-3 py-2 font-mono text-[10.5px] leading-[1.45] text-mute-3" aria-live="polite" x-text="description"></div>
                </aside>

                <x-ui.panel class="min-w-0">
                    <x-slot:header>
                        <span class="font-display text-[11.5px] font-semibold uppercase tracking-[0.07em] text-ink-4">SQL</span>
                        <div class="ml-auto flex min-w-0 flex-wrap items-center justify-end gap-1.5">
                            <span x-show="formatNote" x-text="formatNote" role="status"
                                  class="hidden font-mono text-[11px] sm:inline"
                                  :class="formatFailed ? 'text-danger' : 'text-ok'"></span>
                            <x-ui.btn size="sm" icon="database"
                                      x-ref="toggle"
                                      x-on:click="toggleShown()"
                                      x-bind:aria-expanded="String(shown)"
                                      aria-controls="schema-panel">Schema</x-ui.btn>
                            <x-ui.btn size="sm" icon="zap"
                                      x-on:click="format()"
                                      title="Format SQL (Ctrl/Cmd+Shift+F while the editor has focus)">Format</x-ui.btn>
                        </div>
                    </x-slot:header>

                    <div class="bg-panel">
                        <div x-init="mountEditor($el)"></div>
                    </div>

                    <p x-show="formatNote && formatFailed" x-text="formatNote" role="status"
                       class="border-t border-line px-3.5 py-2 text-[12px] text-danger sm:hidden"></p>
                </x-ui.panel>
            </div>
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

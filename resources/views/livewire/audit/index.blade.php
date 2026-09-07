<div>
    <x-ui.page-header
        title="Audit Log"
        subtitle="Append-only trail of every security-relevant event in this team: who asked, who decided, what actually ran, and what came back. Rows are never edited or deleted.">
        <x-slot:actions>
            <x-ui.btn :href="route('audit.export', ['action' => $action, 'actor' => $actor, 'from' => $from, 'to' => $to, 'scope' => $scope])" icon="download">
                Export CSV
            </x-ui.btn>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.panel padded class="mb-3.5">
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 @if(auth()->user()->isAdmin()) sm:grid-cols-5 @endif">
            @if (auth()->user()->isAdmin())
                <x-ui.field label="Scope">
                    <x-ui.select wire:model.live="scope">
                        <option value="team">This team</option>
                        <option value="system">System (logins, user admin)</option>
                    </x-ui.select>
                </x-ui.field>
            @endif

            <x-ui.field label="Action">
                <x-ui.select wire:model.live="action">
                    <option value="">All actions</option>
                    @foreach ($actions as $a)
                        <option value="{{ $a }}">{{ $a }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            <x-ui.field label="Actor">
                <x-ui.input type="text" wire:model.live.debounce.400ms="actor" placeholder="name or email" />
            </x-ui.field>

            <x-ui.field label="From">
                <x-ui.input type="date" wire:model.live="from" class="font-mono" />
            </x-ui.field>

            <x-ui.field label="To">
                <x-ui.input type="date" wire:model.live="to" class="font-mono" />
            </x-ui.field>
        </div>
    </x-ui.panel>

    <x-ui.panel>
        <x-ui.table>
            <x-slot:head>
                <tr>
                    <x-ui.th class="w-40">When</x-ui.th>
                    <x-ui.th class="w-52">Action</x-ui.th>
                    <x-ui.th class="w-36">Actor</x-ui.th>
                    <x-ui.th class="w-16">Req</x-ui.th>
                    <x-ui.th>Connection</x-ui.th>
                    <x-ui.th class="w-28">IP</x-ui.th>
                    <x-ui.th class="w-20" align="right"></x-ui.th>
                </tr>
            </x-slot:head>

            @forelse ($logs as $log)
                <x-ui.tr>
                    <x-ui.td mono muted nowrap title="{{ $log->created_at }}">{{ $log->created_at->format('Y-m-d H:i:s') }}</x-ui.td>
                    <x-ui.td>
                        {{-- data-audit-action is the stable hook AuditUiTest asserts on; it
                             distinguishes a table cell from the filter select, which always
                             lists every action name. --}}
                        <code data-audit-action="{{ $log->action }}"
                              class="rounded-badge border border-neutral-line bg-neutral-bg px-1.5 py-0.5 font-mono text-[10.5px] text-ink-4">{{ $log->action }}</code>
                    </x-ui.td>
                    <x-ui.td>{{ $log->user?->name ?? '—' }}</x-ui.td>
                    <x-ui.td mono>
                        @if ($log->query_request_id)
                            <a href="{{ route('requests.show', $log->query_request_id) }}" class="text-accent hover:brightness-125">#{{ $log->query_request_id }}</a>
                        @else
                            <span class="text-mute-4">—</span>
                        @endif
                    </x-ui.td>
                    <x-ui.td mono muted truncate>{{ $log->connection?->name ?? '—' }}</x-ui.td>
                    <x-ui.td mono class="text-mute-4">{{ $log->ip ?? '—' }}</x-ui.td>
                    <x-ui.td align="right">
                        @if ($log->sql || $log->metadata)
                            <x-ui.action wire:click="toggle({{ $log->id }})" tone="accent">
                                {{ $expandedId === $log->id ? 'Hide' : 'Details' }}
                            </x-ui.action>
                        @endif
                    </x-ui.td>
                </x-ui.tr>

                @if ($expandedId === $log->id)
                    <tr class="bg-canvas">
                        <td colspan="7" class="border-b border-line px-3.5 py-3.5">
                            @if ($log->sql)
                                <p class="eyebrow mb-1.5">Statement</p>
                                <x-ui.code class="mb-3 whitespace-pre-wrap">{{ $log->sql }}</x-ui.code>
                            @endif
                            @if ($log->metadata)
                                <p class="eyebrow mb-1.5">Metadata</p>
                                <x-ui.code>{{ json_encode($log->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</x-ui.code>
                            @endif
                        </td>
                    </tr>
                @endif
            @empty
                <x-ui.empty :colspan="7">No audit entries match the filters.</x-ui.empty>
            @endforelse
        </x-ui.table>

        @if ($logs->hasPages())
            <x-slot:footer>{{ $logs->links() }}</x-slot:footer>
        @endif
    </x-ui.panel>
</div>

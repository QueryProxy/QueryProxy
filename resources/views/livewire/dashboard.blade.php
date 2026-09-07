<div>
    <x-ui.page-header title="Dashboard">
        <p class="mt-1.5 text-[12.5px] leading-[1.55] text-mute">
            @if ($team)
                Queue health for <span class="font-medium text-ink-3">{{ $team->name }}</span>. Every number below is scoped to this team.
            @else
                You are not a member of any team yet. Ask an administrator to add you.
            @endif
        </p>
    </x-ui.page-header>

    @if ($team)
        @php
            $tiles = [
                ['key' => 'pending', 'label' => 'Pending approval', 'tone' => 'pending', 'value' => $counts['pending'] ?? 0],
                ['key' => 'active', 'label' => 'Queued / running', 'tone' => 'running', 'value' => ($counts['queued'] ?? 0) + ($counts['running'] ?? 0)],
                ['key' => 'completed', 'label' => 'Completed', 'tone' => 'ok', 'value' => $counts['completed'] ?? 0],
                ['key' => 'failed', 'label' => 'Failed / rejected', 'tone' => 'danger', 'value' => ($counts['failed'] ?? 0) + ($counts['rejected'] ?? 0)],
            ];
        @endphp

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach ($tiles as $tile)
                <x-ui.stat
                    :label="$tile['label']"
                    :value="$tile['value']"
                    :tone="$tile['tone']"
                    :active="$filter === $tile['key']"
                    wire:click="filterBy('{{ $tile['key'] }}')"
                    class="cursor-pointer" />
            @endforeach
        </div>

        <div class="mt-4 grid grid-cols-1 items-start gap-4 xl:grid-cols-[minmax(0,1.75fr)_minmax(0,1fr)]">
            <x-ui.panel>
                <x-slot:header>
                    <span class="font-display text-[11.5px] font-semibold uppercase tracking-[0.07em] text-ink-4">Recent requests</span>
                    @if ($filter !== '')
                        <x-ui.badge tone="accent">filtered</x-ui.badge>
                    @endif
                    <x-ui.action :href="route('requests.index')" tone="accent" class="ml-auto">View all</x-ui.action>
                </x-slot:header>

                <x-ui.table>
                    <x-slot:head>
                        <tr>
                            <x-ui.th class="w-14">Req</x-ui.th>
                            <x-ui.th>Title / SQL</x-ui.th>
                            <x-ui.th class="w-32">Requester</x-ui.th>
                            <x-ui.th class="w-16">Type</x-ui.th>
                            <x-ui.th class="w-28">Status</x-ui.th>
                            <x-ui.th class="w-24" align="right">Submitted</x-ui.th>
                        </tr>
                    </x-slot:head>

                    @forelse ($requests as $request)
                        <x-ui.tr>
                            <x-ui.td mono>
                                <a href="{{ route('requests.show', $request) }}" class="text-accent hover:brightness-125">#{{ $request->id }}</a>
                            </x-ui.td>
                            <x-ui.td truncate>
                                <a href="{{ route('requests.show', $request) }}" class="block truncate text-ink-2 hover:text-accent">
                                    {{ $request->title ?? str($request->sql_original)->limit(60) }}
                                </a>
                            </x-ui.td>
                            <x-ui.td muted nowrap>{{ $request->requester->name }}</x-ui.td>
                            <x-ui.td><x-ui.badge :tone="$request->type->tone()">{{ $request->type->label() }}</x-ui.badge></x-ui.td>
                            <x-ui.td><x-ui.badge :tone="$request->status->tone()">{{ $request->status->label() }}</x-ui.badge></x-ui.td>
                            <x-ui.td mono muted nowrap align="right" title="{{ $request->created_at }}">{{ $request->created_at->diffForHumans() }}</x-ui.td>
                        </x-ui.tr>
                    @empty
                        <x-ui.empty :colspan="6">
                            {{ $filter === '' ? 'No requests yet.' : 'No requests with that status.' }}
                        </x-ui.empty>
                    @endforelse
                </x-ui.table>
            </x-ui.panel>

            <div class="flex flex-col gap-4">
                @if ($canDecide)
                    <x-ui.panel>
                        <x-slot:header>
                            <span class="font-display text-[11.5px] font-semibold uppercase tracking-[0.07em] text-ink-4">Awaiting your decision</span>
                            @if ($awaiting->isNotEmpty())
                                <x-ui.badge tone="pending" class="ml-auto">{{ $awaiting->count() }} open</x-ui.badge>
                            @endif
                        </x-slot:header>

                        @forelse ($awaiting as $request)
                            <div class="border-b border-line p-3.5 last:border-b-0">
                                <div class="mb-2 flex items-center gap-2">
                                    <a href="{{ route('requests.show', $request) }}" class="font-mono text-[12px] text-accent hover:brightness-125">#{{ $request->id }}</a>
                                    <x-ui.badge :tone="$request->type->tone()">{{ $request->type->label() }}</x-ui.badge>
                                    <span class="ml-auto font-mono text-[10.5px] text-mute-4">{{ $request->created_at->diffForHumans() }}</span>
                                </div>

                                @if ($request->title)
                                    <p class="mb-1 truncate text-[12.5px] font-medium text-ink-2">{{ $request->title }}</p>
                                @endif
                                <p class="mb-2.5 font-mono text-[10.5px] text-mute-3">{{ $request->requester->name }} · {{ $request->connection->name }}</p>

                                <x-ui.code class="mb-2.5 max-h-24 overflow-y-auto whitespace-pre-wrap">{{ $request->sql_prepared }}</x-ui.code>

                                <div class="flex gap-2">
                                    <x-ui.btn :href="route('requests.show', $request)" size="sm" class="flex-1">Review</x-ui.btn>
                                    <x-ui.btn wire:click="approve({{ $request->id }})"
                                              wire:confirm="Approve and execute request #{{ $request->id }}?"
                                              variant="ok" size="sm" icon="check" class="flex-1">Approve</x-ui.btn>
                                </div>
                            </div>
                        @empty
                            <x-ui.empty>Nothing waiting on you.</x-ui.empty>
                        @endforelse
                    </x-ui.panel>
                @endif

                <x-ui.panel>
                    <x-slot:header>
                        <span class="font-display text-[11.5px] font-semibold uppercase tracking-[0.07em] text-ink-4">
                            {{ $canDecide ? 'Connections' : 'Your connections' }}
                        </span>
                        @if ($canDecide)
                            <x-ui.action :href="route('connections.index')" tone="accent" class="ml-auto">Manage</x-ui.action>
                        @endif
                    </x-slot:header>

                    @forelse ($connections as $connection)
                        <div class="flex items-center gap-2.5 border-b border-line px-3.5 py-2.5 last:border-b-0">
                            <x-ui.icon name="database" :size="14" class="text-mute-3" />
                            <span class="truncate font-mono text-[12px] text-ink-2">{{ $connection->name }}</span>
                            <x-ui.badge class="ml-auto">{{ $connection->driver->label() }}</x-ui.badge>
                            @if ($canDecide)
                                <span class="font-mono text-[10.5px] text-mute-4">{{ $connection->granted_users_count }} granted</span>
                            @endif
                        </div>
                    @empty
                        <x-ui.empty>
                            {{ $canDecide ? 'No connections yet.' : 'No connection has been granted to you yet.' }}
                        </x-ui.empty>
                    @endforelse
                </x-ui.panel>
            </div>
        </div>
    @endif
</div>

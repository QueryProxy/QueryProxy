<div>
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Audit Log</h1>
            <p class="mt-1 text-sm text-slate-500">Immutable trail of every security-relevant event in this team.</p>
        </div>
        <a href="{{ route('audit.export', ['action' => $action, 'actor' => $actor, 'from' => $from, 'to' => $to]) }}"
           class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
            Export CSV
        </a>
    </div>

    <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div>
            <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Action</label>
            <select wire:model.live="action" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                <option value="">All actions</option>
                @foreach($actions as $a)
                    <option value="{{ $a }}">{{ $a }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Actor</label>
            <input type="text" wire:model.live.debounce.400ms="actor" placeholder="name or email"
                   class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">From</label>
            <input type="date" wire:model.live="from" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">To</label>
            <input type="date" wire:model.live="to" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3">When</th>
                    <th class="px-4 py-3">Action</th>
                    <th class="px-4 py-3">Actor</th>
                    <th class="px-4 py-3">Request</th>
                    <th class="px-4 py-3">Connection</th>
                    <th class="px-4 py-3">IP</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($logs as $log)
                    <tr class="hover:bg-slate-50">
                        <td class="whitespace-nowrap px-4 py-2.5 text-slate-500" title="{{ $log->created_at }}">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                        <td class="px-4 py-2.5"><code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs">{{ $log->action }}</code></td>
                        <td class="px-4 py-2.5">{{ $log->user?->name ?? '—' }}</td>
                        <td class="px-4 py-2.5">
                            @if($log->query_request_id)
                                <a href="{{ route('requests.show', $log->query_request_id) }}" class="font-medium text-indigo-600 hover:text-indigo-500">#{{ $log->query_request_id }}</a>
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-2.5 text-slate-500">{{ $log->connection?->name ?? '—' }}</td>
                        <td class="px-4 py-2.5 font-mono text-xs text-slate-400">{{ $log->ip ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-right">
                            @if($log->sql || $log->metadata)
                                <button wire:click="toggle({{ $log->id }})" class="text-xs font-medium text-indigo-600 hover:text-indigo-500">
                                    {{ $expandedId === $log->id ? 'Hide' : 'Details' }}
                                </button>
                            @endif
                        </td>
                    </tr>
                    @if($expandedId === $log->id)
                        <tr class="bg-slate-50">
                            <td colspan="7" class="px-4 py-3">
                                @if($log->sql)
                                    <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">SQL</p>
                                    <pre class="mb-3 overflow-x-auto rounded bg-white p-3 font-mono text-xs">{{ $log->sql }}</pre>
                                @endif
                                @if($log->metadata)
                                    <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Metadata</p>
                                    <pre class="overflow-x-auto rounded bg-white p-3 font-mono text-xs">{{ json_encode($log->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                @endif
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-slate-400">No audit entries match the filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $logs->links() }}</div>
</div>

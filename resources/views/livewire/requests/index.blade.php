<div>
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Query Requests</h1>
            <p class="mt-1 text-sm text-slate-500">Track submission, approval and execution status.</p>
        </div>
        <select wire:model.live="status" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
            <option value="">All statuses</option>
            @foreach(\App\Enums\QueryRequestStatus::cases() as $s)
                <option value="{{ $s->value }}">{{ $s->label() }}</option>
            @endforeach
        </select>
    </div>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3">#</th>
                    <th class="px-4 py-3">Title / SQL</th>
                    <th class="px-4 py-3">Connection</th>
                    <th class="px-4 py-3">Requester</th>
                    <th class="px-4 py-3">Type</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Submitted</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($requests as $request)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 text-slate-500">
                            <a href="{{ route('requests.show', $request) }}" class="font-medium text-indigo-600 hover:text-indigo-500">#{{ $request->id }}</a>
                        </td>
                        <td class="max-w-xs px-4 py-3">
                            <a href="{{ route('requests.show', $request) }}" class="block truncate font-medium text-slate-800 hover:text-indigo-600">
                                {{ $request->title ?? str($request->sql_original)->limit(60) }}
                            </a>
                        </td>
                        <td class="px-4 py-3 text-slate-500">{{ $request->connection->name }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ $request->requester->name }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded px-1.5 py-0.5 text-xs font-bold uppercase {{ $request->type->value === 'read' ? 'bg-sky-100 text-sky-700' : 'bg-orange-100 text-orange-700' }}">
                                {{ $request->type->value }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <x-ui.badge :tone="$request->status->tone()">{{ $request->status->label() }}</x-ui.badge>
                        </td>
                        <td class="px-4 py-3 text-slate-500" title="{{ $request->created_at }}">{{ $request->created_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-slate-400">No requests yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $requests->links() }}</div>
</div>

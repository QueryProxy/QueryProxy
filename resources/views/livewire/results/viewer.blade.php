<div class="rounded-xl border border-slate-200 bg-white shadow-sm">
    <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
        <div class="text-sm font-semibold text-slate-700">
            Results
            <span class="ml-1 font-normal text-slate-400">{{ number_format($queryRequest->result_row_count) }} rows (masked)</span>
        </div>
        <a href="{{ route('requests.download', $queryRequest) }}"
           class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">
            Download CSV
        </a>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    @foreach($columns as $column)
                        <th class="whitespace-nowrap px-4 py-2 font-mono">{{ $column }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 font-mono text-xs">
                @forelse($rows as $row)
                    <tr class="hover:bg-slate-50">
                        @foreach($row as $value)
                            <td class="max-w-xs truncate whitespace-nowrap px-4 py-1.5 {{ $value === null ? 'italic text-slate-300' : 'text-slate-700' }}">
                                {{ $value === null ? 'NULL' : (is_scalar($value) ? $value : json_encode($value)) }}
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ max(count($columns), 1) }}" class="px-4 py-8 text-center font-sans text-slate-400">Empty result set.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($lastPage > 1)
        <div class="flex items-center justify-between border-t border-slate-100 px-5 py-3 text-sm">
            <button wire:click="previousPage" @disabled($page <= 1)
                    class="rounded-md border border-slate-300 px-3 py-1.5 font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-40">
                ← Previous
            </button>
            <span class="text-slate-500">Page {{ $page }} / {{ $lastPage }}</span>
            <button wire:click="nextPage" @disabled($page >= $lastPage)
                    class="rounded-md border border-slate-300 px-3 py-1.5 font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-40">
                Next →
            </button>
        </div>
    @endif
</div>

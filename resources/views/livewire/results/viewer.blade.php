<x-ui.panel>
    <x-slot:header>
        <span class="font-display text-[11.5px] font-semibold uppercase tracking-[0.07em] text-ink-4">Results</span>
        <span class="font-mono text-[11px] text-mute-3">{{ number_format($queryRequest->result_row_count) }} rows (masked)</span>
        <x-ui.btn :href="route('requests.download', $queryRequest)" size="sm" icon="download" class="ml-auto">Download CSV</x-ui.btn>
    </x-slot:header>

    <x-ui.table>
        <x-slot:head>
            <tr>
                @foreach ($columns as $column)
                    <x-ui.th class="font-mono normal-case tracking-normal">{{ $column }}</x-ui.th>
                @endforeach
            </tr>
        </x-slot:head>

        @forelse ($rows as $row)
            <x-ui.tr>
                @foreach ($row as $value)
                    <x-ui.td mono nowrap class="max-w-0 truncate {{ $value === null ? 'text-hairline italic' : '' }}">
                        {{ $value === null ? 'NULL' : (is_scalar($value) ? $value : json_encode($value)) }}
                    </x-ui.td>
                @endforeach
            </x-ui.tr>
        @empty
            <x-ui.empty :colspan="max(count($columns), 1)">Empty result set.</x-ui.empty>
        @endforelse
    </x-ui.table>

    @if ($lastPage > 1)
        {{-- Comparisons live out here: a < or > inside a component tag breaks Blade's tag parser. --}}
        @php
            $atFirstPage = $page <= 1;
            $atLastPage = $page >= $lastPage;
        @endphp

        <x-slot:footer>
            <x-ui.btn wire:click="previousPage" size="sm" icon="arrow-left" :disabled="$atFirstPage">Previous</x-ui.btn>
            <span class="mx-auto font-mono text-[11.5px] text-mute-3">Page {{ $page }} / {{ $lastPage }}</span>
            <x-ui.btn wire:click="nextPage" size="sm" icon="arrow-right" :disabled="$atLastPage">Next</x-ui.btn>
        </x-slot:footer>
    @endif
</x-ui.panel>

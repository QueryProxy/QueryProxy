@props(['columns' => [], 'variant' => 'badge'])

{{-- Warning for approvers: the query filters on a masked column, so its real values can be probed. --}}
@if (count($columns) > 0)
    @if ($variant === 'alert')
        <x-ui.alert tone="pending" icon="mask" {{ $attributes }}>
            <span class="font-medium">Sensitive column in filter:</span>
            <span class="font-mono">{{ implode(', ', $columns) }}</span>.
            Results are masked, but filtering on a masked column can still reveal its values.
        </x-ui.alert>
    @else
        <x-ui.badge tone="pending" {{ $attributes }} title="Filtering on a masked column can reveal its values">
            sensitive filter · {{ implode(', ', array_slice($columns, 0, 3)) }}@if (count($columns) > 3) +{{ count($columns) - 3 }}@endif
        </x-ui.badge>
    @endif
@endif

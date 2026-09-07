@props(['colspan' => null])

@if ($colspan)
    <tr>
        <td colspan="{{ $colspan }}" {{ $attributes->class(['px-3.5 py-8 text-center text-[12.5px] text-mute-4']) }}>{{ $slot }}</td>
    </tr>
@else
    <div {{ $attributes->class(['px-4 py-10 text-center text-[12.5px] text-mute-4']) }}>{{ $slot }}</div>
@endif

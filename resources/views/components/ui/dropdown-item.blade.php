@props(['href' => null, 'type' => 'button', 'active' => false])

@php
    $classes = [
        'flex w-full items-center gap-2 px-3 py-2 text-left transition-colors hover:bg-raised hover:text-ink',
        'font-medium text-accent' => $active,
    ];
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif

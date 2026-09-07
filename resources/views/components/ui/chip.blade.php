@props(['active' => false, 'href' => null, 'static' => false])

@php
    $classes = [
        'inline-flex h-[27px] items-center gap-1.5 whitespace-nowrap rounded-[5px] border px-2.5',
        'font-mono text-[11px] tracking-[0.03em] transition-colors outline-none',
        'border-accent bg-accent-bg text-ink' => $active,
        'border-line bg-panel text-ink-5' => ! $active,
        'hover:border-line-strong hover:text-ink-2' => ! $active && ! $static,
        'cursor-default' => $static,
    ];
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@elseif ($static)
    <span {{ $attributes->class($classes) }}>{{ $slot }}</span>
@else
    <button type="button" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif

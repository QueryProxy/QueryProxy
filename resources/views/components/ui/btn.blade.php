@props(['variant' => 'secondary', 'size' => 'md', 'href' => null, 'icon' => null, 'type' => 'button'])

@php
    $variants = [
        'primary' => 'bg-accent border-accent text-accent-ink font-semibold hover:brightness-110',
        'secondary' => 'bg-raised border-line-strong text-ink-3 hover:border-hairline hover:text-ink',
        'ghost' => 'bg-transparent border-transparent text-ink-4 hover:bg-raised hover:text-ink',
        'ok' => 'bg-ok-bg border-ok-line text-ok font-medium hover:brightness-125',
        'danger' => 'bg-danger-bg border-danger-line text-danger font-medium hover:brightness-125',
    ];
    $sizes = [
        'md' => 'h-[30px] px-3 text-[12.5px]',
        'sm' => 'h-[26px] px-2.5 text-[12px]',
    ];
    $classes = [
        'inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-control border',
        'font-medium transition-[background-color,border-color,color,filter] outline-none',
        'focus-visible:ring-2 focus-visible:ring-accent/40 disabled:pointer-events-none disabled:opacity-40',
        $sizes[$size] ?? $sizes['md'],
        $variants[$variant] ?? $variants['secondary'],
    ];
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>
        @if ($icon)<x-ui.icon :name="$icon" :size="14" />@endif
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>
        @if ($icon)<x-ui.icon :name="$icon" :size="14" />@endif
        {{ $slot }}
    </button>
@endif

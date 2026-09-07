@props(['label', 'value', 'tone' => 'neutral', 'sub' => null, 'active' => false, 'href' => null])

@php
    $dots = [
        'neutral' => 'bg-ink-5',
        'accent' => 'bg-accent',
        'pending' => 'bg-pending',
        'running' => 'bg-running',
        'ok' => 'bg-ok',
        'danger' => 'bg-danger',
    ];
    $values = [
        'neutral' => 'text-ink',
        'accent' => 'text-accent',
        'pending' => 'text-pending',
        'running' => 'text-running',
        'ok' => 'text-ok',
        'danger' => 'text-danger',
    ];
    $classes = [
        'flex flex-col gap-2.5 rounded-panel border bg-panel p-3.5 text-left transition-colors',
        'border-accent bg-raised' => $active,
        'border-line hover:border-line-strong' => ! $active,
    ];
@endphp

<{{ $href ? 'a' : 'div' }} @if ($href) href="{{ $href }}" @endif {{ $attributes->class($classes) }}>
    <span class="eyebrow flex items-center gap-2">
        <span class="size-1.5 rounded-full {{ $dots[$tone] ?? $dots['neutral'] }}"></span>
        {{ $label }}
    </span>
    <span class="font-display text-[27px] font-semibold leading-none tracking-[-0.02em] {{ $active ? ($values[$tone] ?? $values['neutral']) : 'text-ink' }}">{{ $value }}</span>
    @if ($sub)
        <span class="text-[11px] text-mute-4">{{ $sub }}</span>
    @endif
</{{ $href ? 'a' : 'div' }}>

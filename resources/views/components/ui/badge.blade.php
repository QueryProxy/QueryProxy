@props(['tone' => 'neutral'])

@php
    $tones = [
        'neutral' => 'text-ink-4 bg-neutral-bg border-neutral-line',
        'queued' => 'text-ink-4 bg-neutral-bg border-neutral-line',
        'accent' => 'text-accent bg-accent-bg border-accent-line',
        'pending' => 'text-pending bg-pending-bg border-pending-line',
        'running' => 'text-running bg-running-bg border-running-line',
        'read' => 'text-running bg-running-bg border-running-line',
        'ok' => 'text-ok bg-ok-bg border-ok-line',
        'danger' => 'text-danger bg-danger-bg border-danger-line',
        'write' => 'text-write bg-write-bg border-write-line',
    ];
@endphp

<span {{ $attributes->class([
    'inline-flex h-[18px] items-center gap-1.5 whitespace-nowrap rounded-badge border px-1.5',
    'font-mono text-[10px] font-medium uppercase tracking-[0.09em]',
    $tones[$tone] ?? $tones['neutral'],
]) }}>{{ $slot }}</span>

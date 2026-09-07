@props(['tone' => 'accent', 'icon' => null])

@php
    $tones = [
        'accent' => 'text-ink-3 bg-accent-bg border-accent-line',
        'pending' => 'text-ink-3 bg-pending-bg border-pending-line',
        'ok' => 'text-ink-3 bg-ok-bg border-ok-line',
        'danger' => 'text-ink-3 bg-danger-bg border-danger-line',
        'neutral' => 'text-ink-3 bg-neutral-bg border-neutral-line',
    ];
    $marks = [
        'accent' => 'text-accent',
        'pending' => 'text-pending',
        'ok' => 'text-ok',
        'danger' => 'text-danger',
        'neutral' => 'text-ink-4',
    ];
@endphp

<div {{ $attributes->class([
    'flex items-start gap-2.5 rounded-[7px] border px-3 py-2.5 text-[12px] leading-[1.5]',
    $tones[$tone] ?? $tones['accent'],
]) }}>
    @if ($icon)
        <x-ui.icon :name="$icon" :size="15" class="mt-px {{ $marks[$tone] ?? $marks['accent'] }}" />
    @endif
    <div class="min-w-0">{{ $slot }}</div>
</div>

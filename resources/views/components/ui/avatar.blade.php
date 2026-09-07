@props(['name' => '', 'size' => 'md'])

@php
    $sizes = [
        'sm' => 'size-5 rounded-[4px] text-[9px]',
        'md' => 'size-[26px] rounded-[5px] text-[10px]',
    ];
    $initials = collect(preg_split('/\s+/', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY))
        ->take(2)
        ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('');
@endphp

<span {{ $attributes->class([
    'inline-flex shrink-0 items-center justify-center bg-raised font-mono font-semibold tracking-[0.04em] text-accent',
    $sizes[$size] ?? $sizes['md'],
]) }} title="{{ $name }}">{{ $initials }}</span>

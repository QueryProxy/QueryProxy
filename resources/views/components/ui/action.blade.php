{{-- Inline text action for table rows and card footers. --}}
@props(['tone' => 'default', 'href' => null, 'type' => 'button'])

@php
    $tones = [
        'default' => 'text-ink-5 hover:text-ink',
        'accent' => 'text-accent hover:brightness-125',
        'danger' => 'text-danger/80 hover:text-danger',
    ];
    $classes = ['text-[11.5px] font-medium transition-colors outline-none focus-visible:underline disabled:opacity-40', $tones[$tone] ?? $tones['default']];
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif

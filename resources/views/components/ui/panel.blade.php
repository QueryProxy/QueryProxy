@props(['title' => null, 'padded' => false])

<div {{ $attributes->class(['overflow-hidden rounded-panel border border-line bg-panel']) }}>
    @if (isset($header) || $title)
        <div class="flex items-center gap-2.5 border-b border-line bg-header px-3.5 py-2.5">
            @isset($header)
                {{ $header }}
            @else
                <span class="font-display text-[11.5px] font-semibold uppercase tracking-[0.07em] text-ink-4">{{ $title }}</span>
            @endisset
        </div>
    @endif

    @if ($padded)
        <div class="p-3.5">{{ $slot }}</div>
    @else
        {{ $slot }}
    @endif

    @isset($footer)
        <div class="flex items-center gap-2.5 border-t border-line px-3.5 py-2.5">{{ $footer }}</div>
    @endisset
</div>

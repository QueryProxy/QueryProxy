@props(['title', 'subtitle' => null])

<div {{ $attributes->class(['mb-5 flex items-start gap-4']) }}>
    <div class="min-w-0">
        <h1 class="font-display text-[19px] font-semibold tracking-[-0.012em] text-ink">{{ $title }}</h1>
        @if ($subtitle)
            <p class="mt-1.5 max-w-[78ch] text-pretty text-[12.5px] leading-[1.55] text-mute">{{ $subtitle }}</p>
        @endif
        {{ $slot }}
    </div>
    @isset($actions)
        <div class="ml-auto flex shrink-0 items-center gap-2">{{ $actions }}</div>
    @endisset
</div>

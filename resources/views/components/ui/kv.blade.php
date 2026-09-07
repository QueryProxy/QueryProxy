@props(['label', 'hint' => null])

<div {{ $attributes->class(['flex items-baseline justify-between gap-3 py-1']) }}>
    <dt class="shrink-0 text-[12px] text-mute-2">{{ $label }}</dt>
    <dd class="min-w-0 truncate text-right text-[12.5px] text-ink-2">
        {{ $slot }}
        @if ($hint)<span class="ml-1 font-mono text-[10.5px] text-mute-4">{{ $hint }}</span>@endif
    </dd>
</div>

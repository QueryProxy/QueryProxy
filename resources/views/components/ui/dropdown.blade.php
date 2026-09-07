@props(['align' => 'right', 'width' => 'w-56'])

<div x-data="{ open: false }" {{ $attributes->class(['relative']) }}>
    <div @click="open = ! open" @click.outside="open = false">{{ $trigger }}</div>

    <div x-show="open" x-transition.opacity x-cloak
         class="absolute z-20 mt-1 {{ $width }} {{ $align === 'left' ? 'left-0' : 'right-0' }} overflow-hidden rounded-control border border-line-strong bg-panel py-1 text-[12.5px] text-ink-3 shadow-xl">
        {{ $slot }}
    </div>
</div>

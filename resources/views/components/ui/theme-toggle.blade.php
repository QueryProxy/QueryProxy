{{-- Flips <html data-theme>; the handler is defined in <x-theme-script> so it works before Alpine boots. --}}
<button type="button" onclick="qpTheme.toggle()" aria-label="Switch colour theme"
        {{ $attributes->class([
            'inline-flex size-7 items-center justify-center rounded-control text-ink-5 transition-colors',
            'hover:bg-raised hover:text-ink outline-none focus-visible:ring-2 focus-visible:ring-accent/40',
        ]) }}>
    <x-ui.icon name="sun" class="theme-dark-only" />
    <x-ui.icon name="moon" class="theme-light-only" />
</button>

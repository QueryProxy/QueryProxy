<select {{ $attributes->class([
    'h-[30px] w-full rounded-control border border-line bg-canvas px-2.5 text-[12.5px] text-ink-2',
    'outline-none transition-colors focus:border-accent disabled:opacity-50',
]) }}>{{ $slot }}</select>

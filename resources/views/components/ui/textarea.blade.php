<textarea {{ $attributes->class([
    'w-full rounded-control border border-line bg-canvas px-2.5 py-2 text-[12.5px] leading-[1.6] text-ink-2',
    'outline-none transition-colors placeholder:text-hairline focus:border-accent',
]) }}>{{ $slot }}</textarea>

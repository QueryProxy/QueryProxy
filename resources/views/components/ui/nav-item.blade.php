@props(['href', 'active' => false, 'icon' => null, 'count' => null])

<a href="{{ $href }}" @if ($active) aria-current="page" @endif {{ $attributes->class([
    'flex h-[30px] items-center gap-2.5 rounded-[5px] border-l-2 pr-2 pl-1.5 text-[12.5px] transition-colors',
    'border-accent bg-raised text-ink' => $active,
    'border-transparent text-ink-5 hover:bg-raised hover:text-ink-2' => ! $active,
]) }}>
    @if ($icon)
        <x-ui.icon :name="$icon" class="{{ $active ? 'text-accent' : '' }}" />
    @endif
    <span class="truncate">{{ $slot }}</span>
    @if ($count)
        <span class="ml-auto rounded-[3px] border px-1.5 font-mono text-[10.5px] {{ $active ? 'border-accent-line bg-accent-bg text-accent' : 'border-neutral-line bg-neutral-bg text-ink-4' }}">{{ $count }}</span>
    @endif
</a>

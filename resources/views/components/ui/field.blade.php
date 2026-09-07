@props(['label' => null, 'for' => null, 'hint' => null, 'error' => null])

<div {{ $attributes->class(['min-w-0']) }}>
    @if ($label)
        <label @if ($for) for="{{ $for }}" @endif class="eyebrow mb-1.5 block">
            {{ $label }}
            @if ($hint)<span class="normal-case tracking-normal text-hairline">{{ $hint }}</span>@endif
        </label>
    @endif

    {{ $slot }}

    @if ($error)
        <p class="mt-1.5 text-[12px] text-danger">{{ $error }}</p>
    @endif
</div>

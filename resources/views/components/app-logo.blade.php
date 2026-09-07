@props(['size' => 19])

<svg {{ $attributes->class(['shrink-0 text-accent']) }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24"
     fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <ellipse cx="12" cy="5.4" rx="7.4" ry="2.9" />
    <path d="M4.6 5.4v12.2c0 1.6 3.3 2.9 7.4 2.9" />
    <path d="M19.4 5.4v5.4" />
    <path d="M15.6 12.4l-2.8 4.4h3.4l-2.8 4.4" class="text-pending" stroke="currentColor" />
</svg>

@props(['title' => null, 'heading' => null, 'subheading' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title.' — ' : '' }}{{ config('app.name', 'QueryProxy') }}</title>
    <x-theme-script />
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-full items-center justify-center bg-canvas font-sans text-ink-3 antialiased">
<div class="w-full max-w-sm px-4 py-10">
    <div class="mb-7 flex items-center justify-between">
        <a href="{{ route('login') }}" class="flex items-center gap-2.5 text-ink">
            <x-app-logo :size="22" />
            <span class="font-display text-[13px] font-semibold uppercase tracking-[0.16em]">QueryProxy</span>
        </a>
        <x-ui.theme-toggle />
    </div>

    <x-ui.panel padded>
        @if ($heading)
            <h1 class="font-display text-[17px] font-semibold tracking-[-0.012em] text-ink">{{ $heading }}</h1>
        @endif
        @if ($subheading)
            <p class="mt-1.5 text-[12.5px] leading-[1.55] text-mute">{{ $subheading }}</p>
        @endif

        <div @class(['mt-4' => $heading || $subheading])>
            {{ $slot }}
        </div>
    </x-ui.panel>

    <p class="mt-5 text-center font-mono text-[11px] text-mute-4">Self-hosted database access control</p>
</div>
</body>
</html>

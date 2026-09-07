@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' — ' : '' }}{{ config('app.name', 'QueryProxy') }}</title>
    <x-theme-script />
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-full bg-canvas font-sans text-ink-3 antialiased">
@php
    $user = auth()->user();
    $team = $user?->currentTeam();
    $role = $team ? $user->roleIn($team) : null;
    $isDba = $role === \App\Enums\TeamRole::Dba || $user?->isAdmin();
@endphp

<div class="grid min-h-screen grid-cols-[232px_minmax(0,1fr)]">
    <aside class="flex min-h-0 flex-col border-r border-line bg-header">
        <div class="flex h-12 items-center gap-2.5 border-b border-line px-3.5">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5 text-ink">
                <x-app-logo />
                <span class="font-display text-[12.5px] font-semibold uppercase tracking-[0.16em]">QueryProxy</span>
            </a>
        </div>

        @if ($user)
            @php $teams = $user->accessibleTeams(); @endphp
            @if ($teams->count() > 0)
                <x-ui.dropdown align="left" width="w-56" class="mx-3 mt-3 mb-0.5">
                    <x-slot:trigger>
                        <button type="button" class="flex w-full items-center gap-2 rounded-control border border-line bg-panel px-2.5 py-2 text-left transition-colors hover:border-line-strong">
                            <span class="size-1.5 shrink-0 rounded-full bg-ok"></span>
                            <span class="truncate text-[12.5px] font-medium text-ink">{{ $team?->name ?? 'No team' }}</span>
                            @if ($role)
                                <x-ui.badge tone="accent" class="ml-auto">{{ $role->label() }}</x-ui.badge>
                            @elseif ($user->isAdmin())
                                <x-ui.badge tone="pending" class="ml-auto">Admin</x-ui.badge>
                            @endif
                            <x-ui.icon name="chevron-down" :size="14" class="text-mute-3" />
                        </button>
                    </x-slot:trigger>

                    @foreach ($teams as $t)
                        <form method="POST" action="{{ route('teams.switch', $t) }}">
                            @csrf
                            <x-ui.dropdown-item type="submit" :active="$team && $t->id === $team->id">
                                <span class="truncate">{{ $t->name }}</span>
                                @if ($rt = $user->roleIn($t))
                                    <span class="ml-auto text-[11px] text-mute-3">{{ $rt->label() }}</span>
                                @endif
                            </x-ui.dropdown-item>
                        </form>
                    @endforeach
                </x-ui.dropdown>
            @endif
        @endif

        <nav class="flex min-h-0 flex-1 flex-col gap-px overflow-y-auto p-2">
            <div class="eyebrow px-2 pt-3 pb-1.5">Workspace</div>
            <x-ui.nav-item :href="route('dashboard')" :active="request()->routeIs('dashboard')" icon="dashboard">Dashboard</x-ui.nav-item>

            @if ($team)
                @if (Route::has('studio') && ($role === \App\Enums\TeamRole::Developer || $role === \App\Enums\TeamRole::Dba || $user->isAdmin()))
                    <x-ui.nav-item :href="route('studio')" :active="request()->routeIs('studio')" icon="terminal">Query Studio</x-ui.nav-item>
                @endif
                @if (Route::has('requests.index') && $role !== \App\Enums\TeamRole::Auditor)
                    <x-ui.nav-item :href="route('requests.index')" :active="request()->routeIs('requests.*')" icon="list">Requests</x-ui.nav-item>
                @endif

                @if ($isDba)
                    <div class="eyebrow px-2 pt-3.5 pb-1.5">Governance</div>
                    @if (Route::has('approvals.index'))
                        <x-ui.nav-item :href="route('approvals.index')" :active="request()->routeIs('approvals.*')" icon="shield">Approvals</x-ui.nav-item>
                    @endif
                    @if (Route::has('connections.index'))
                        <x-ui.nav-item :href="route('connections.index')" :active="request()->routeIs('connections.*')" icon="database">Connections</x-ui.nav-item>
                    @endif
                    @if (Route::has('masking.index'))
                        <x-ui.nav-item :href="route('masking.index')" :active="request()->routeIs('masking.*')" icon="mask">Masking</x-ui.nav-item>
                    @endif
                    @if (Route::has('settings.chatops'))
                        <x-ui.nav-item :href="route('settings.chatops')" :active="request()->routeIs('settings.*')" icon="chat">ChatOps</x-ui.nav-item>
                    @endif
                @endif

                @if (Route::has('audit.index') && ($role === \App\Enums\TeamRole::Auditor || $user->isAdmin()))
                    <div class="eyebrow px-2 pt-3.5 pb-1.5">Records</div>
                    <x-ui.nav-item :href="route('audit.index')" :active="request()->routeIs('audit.*')" icon="audit">Audit Log</x-ui.nav-item>
                @endif
            @endif

            @if (Route::has('admin.teams') && $user?->isAdmin())
                <div class="eyebrow px-2 pt-3.5 pb-1.5">Administration</div>
                <x-ui.nav-item :href="route('admin.teams')" :active="request()->routeIs('admin.*')" icon="users">Teams &amp; Users</x-ui.nav-item>
            @endif
        </nav>

        @if ($user)
            <div class="flex items-center gap-2.5 border-t border-line px-3 py-2.5">
                <x-ui.avatar :name="$user->name" />
                <div class="min-w-0">
                    <div class="truncate text-[12px] font-medium text-ink">{{ $user->name }}</div>
                    <div class="font-mono text-[10px] text-mute-4">self-hosted</div>
                </div>
                <x-ui.theme-toggle class="ml-auto" />
            </div>
        @endif
    </aside>

    <div class="flex min-w-0 flex-col">
        <header class="flex h-12 shrink-0 items-center gap-3 border-b border-line bg-header px-4">
            <span class="truncate font-mono text-[12px] text-mute-3">
                {{ $team ? \Illuminate\Support\Str::slug($team->name) : 'no-team' }}<span class="text-hairline"> / </span><span class="text-ink-3">{{ $title ? \Illuminate\Support\Str::slug($title) : 'dashboard' }}</span>
            </span>

            @if ($user)
                <div class="ml-auto flex items-center gap-2">
                    <livewire:notification-bell />

                    <x-ui.dropdown width="w-52">
                        <x-slot:trigger>
                            <button type="button" class="flex items-center gap-2 rounded-control py-1 pr-2 pl-1 transition-colors hover:bg-raised">
                                <x-ui.avatar :name="$user->name" size="sm" />
                                <span class="hidden text-[12.5px] text-ink-3 sm:inline">{{ $user->name }}</span>
                            </button>
                        </x-slot:trigger>

                        <div class="border-b border-line px-3 py-2 font-mono text-[11px] text-mute-3">{{ $user->email }}</div>
                        <x-ui.dropdown-item :href="route('profile')">
                            <x-ui.icon name="user" :size="14" /> Profile
                        </x-ui.dropdown-item>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-ui.dropdown-item type="submit">
                                <x-ui.icon name="logout" :size="14" /> Log out
                            </x-ui.dropdown-item>
                        </form>
                    </x-ui.dropdown>
                </div>
            @endif
        </header>

        <main class="min-w-0 flex-1 p-5">
            @if (session('status'))
                <x-ui.alert tone="ok" icon="check" class="mb-4">{{ session('status') }}</x-ui.alert>
            @endif

            {{ $slot }}
        </main>
    </div>
</div>

@livewireScripts
</body>
</html>

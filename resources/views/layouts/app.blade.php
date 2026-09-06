@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' — ' : '' }}{{ config('app.name', 'QueryProxy') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-900 antialiased">
@php
    $user = auth()->user();
    $team = $user?->currentTeam();
    $role = $team ? $user->roleIn($team) : null;
@endphp

<nav class="border-b border-slate-800 bg-slate-900 text-slate-100">
    <div class="mx-auto flex h-14 max-w-7xl items-center gap-6 px-4 sm:px-6">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-2 font-semibold tracking-tight">
            <svg class="h-6 w-6 text-indigo-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <ellipse cx="12" cy="5.5" rx="7.5" ry="3" />
                <path d="M4.5 5.5v6c0 1.66 3.36 3 7.5 3s7.5-1.34 7.5-3v-6" />
                <path d="M4.5 11.5v6c0 1.66 3.36 3 7.5 3s7.5-1.34 7.5-3v-6" />
                <path d="M15 13.5l-2.5 4h3l-2.5 4" stroke="currentColor" stroke-linejoin="round" class="text-amber-400" />
            </svg>
            QueryProxy
        </a>

        <div class="hidden items-center gap-1 text-sm md:flex">
            <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">Dashboard</x-nav-link>
            @if($team)
                @if(Route::has('studio') && ($role === \App\Enums\TeamRole::Developer || $role === \App\Enums\TeamRole::Dba || $user->isAdmin()))
                    <x-nav-link :href="route('studio')" :active="request()->routeIs('studio')">Query Studio</x-nav-link>
                @endif
                @if(Route::has('requests.index') && $role !== \App\Enums\TeamRole::Auditor)
                    <x-nav-link :href="route('requests.index')" :active="request()->routeIs('requests.*')">Requests</x-nav-link>
                @endif
                @if($role === \App\Enums\TeamRole::Dba || $user->isAdmin())
                    @if(Route::has('approvals.index'))
                        <x-nav-link :href="route('approvals.index')" :active="request()->routeIs('approvals.*')">Approvals</x-nav-link>
                    @endif
                    @if(Route::has('connections.index'))
                        <x-nav-link :href="route('connections.index')" :active="request()->routeIs('connections.*')">Connections</x-nav-link>
                    @endif
                    @if(Route::has('masking.index'))
                        <x-nav-link :href="route('masking.index')" :active="request()->routeIs('masking.*')">Masking</x-nav-link>
                    @endif
                @endif
                @if(Route::has('audit.index') && ($role === \App\Enums\TeamRole::Auditor || $user->isAdmin()))
                    <x-nav-link :href="route('audit.index')" :active="request()->routeIs('audit.*')">Audit Log</x-nav-link>
                @endif
            @endif
            @if(Route::has('admin.teams') && $user?->isAdmin())
                <x-nav-link :href="route('admin.teams')" :active="request()->routeIs('admin.*')">Admin</x-nav-link>
            @endif
        </div>

        <div class="ml-auto flex items-center gap-3">
            @if($user)
                {{-- Team switcher --}}
                @php $teams = $user->accessibleTeams(); @endphp
                @if($teams->count() > 0)
                    <div x-data="{ open: false }" class="relative">
                        <button @click="open = !open" @click.outside="open = false"
                                class="flex items-center gap-1.5 rounded-md bg-slate-800 px-3 py-1.5 text-sm text-slate-200 hover:bg-slate-700">
                            <span class="text-slate-400">Team:</span>
                            <span class="font-medium">{{ $team?->name ?? '—' }}</span>
                            @if($role)
                                <span class="rounded bg-indigo-500/20 px-1.5 py-0.5 text-xs font-medium text-indigo-300">{{ $role->label() }}</span>
                            @elseif($user->isAdmin())
                                <span class="rounded bg-amber-500/20 px-1.5 py-0.5 text-xs font-medium text-amber-300">Admin</span>
                            @endif
                            <svg class="h-4 w-4 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                        </button>
                        <div x-show="open" x-transition.opacity x-cloak
                             class="absolute right-0 z-20 mt-1 w-56 overflow-hidden rounded-md border border-slate-200 bg-white py-1 text-sm text-slate-700 shadow-lg">
                            @foreach($teams as $t)
                                <form method="POST" action="{{ route('teams.switch', $t) }}">
                                    @csrf
                                    <button type="submit"
                                            class="flex w-full items-center justify-between px-3 py-2 text-left hover:bg-slate-50 {{ $team && $t->id === $team->id ? 'font-semibold text-indigo-600' : '' }}">
                                        {{ $t->name }}
                                        @if($rt = $user->roleIn($t))
                                            <span class="text-xs text-slate-400">{{ $rt->label() }}</span>
                                        @endif
                                    </button>
                                </form>
                            @endforeach
                        </div>
                    </div>
                @endif

                <livewire:notification-bell />

                {{-- User menu --}}
                <div x-data="{ open: false }" class="relative">
                    <button @click="open = !open" @click.outside="open = false"
                            class="flex items-center gap-2 rounded-full py-1 pl-1 pr-2 text-sm hover:bg-slate-800">
                        <span class="flex h-7 w-7 items-center justify-center rounded-full bg-indigo-500 text-xs font-bold text-white">
                            {{ strtoupper(mb_substr($user->name, 0, 1)) }}
                        </span>
                        <span class="hidden text-slate-200 sm:inline">{{ $user->name }}</span>
                    </button>
                    <div x-show="open" x-transition.opacity x-cloak
                         class="absolute right-0 z-20 mt-1 w-44 overflow-hidden rounded-md border border-slate-200 bg-white py-1 text-sm text-slate-700 shadow-lg">
                        <div class="border-b border-slate-100 px-3 py-2 text-xs text-slate-400">{{ $user->email }}</div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="w-full px-3 py-2 text-left hover:bg-slate-50">Log out</button>
                        </form>
                    </div>
                </div>
            @endif
        </div>
    </div>
</nav>

<main class="mx-auto max-w-7xl px-4 py-8 sm:px-6">
    @if(session('status'))
        <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    {{ $slot }}
</main>

@livewireScripts
<style>[x-cloak]{display:none!important}</style>
</body>
</html>

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log in — {{ config('app.name', 'QueryProxy') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen items-center justify-center bg-slate-900 font-sans antialiased">
<div class="w-full max-w-sm px-4">
    <div class="mb-8 text-center">
        <div class="mb-3 inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-indigo-500/15">
            <svg class="h-8 w-8 text-indigo-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <ellipse cx="12" cy="5.5" rx="7.5" ry="3" />
                <path d="M4.5 5.5v6c0 1.66 3.36 3 7.5 3s7.5-1.34 7.5-3v-6" />
                <path d="M4.5 11.5v6c0 1.66 3.36 3 7.5 3s7.5-1.34 7.5-3v-6" />
            </svg>
        </div>
        <h1 class="text-2xl font-bold tracking-tight text-white">QueryProxy</h1>
        <p class="mt-1 text-sm text-slate-400">Database access control &amp; query approval</p>
    </div>

    <div class="rounded-xl bg-white p-6 shadow-xl">
        <form method="POST" action="{{ route('login') }}" class="space-y-4">
            @csrf
            <div>
                <label for="email" class="mb-1 block text-sm font-medium text-slate-700">Email</label>
                <input id="email" name="email" type="email" required autofocus autocomplete="username"
                       value="{{ old('email') }}"
                       class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                @error('email')
                    <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="password" class="mb-1 block text-sm font-medium text-slate-700">Password</label>
                <input id="password" name="password" type="password" required autocomplete="current-password"
                       class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                @error('password')
                    <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>
            <div class="flex items-center justify-between">
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" name="remember" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                    Remember me
                </label>
                <a href="{{ route('password.request') }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-500">Forgot password?</a>
            </div>
            <button type="submit"
                    class="w-full rounded-md bg-indigo-600 py-2 text-sm font-semibold text-white transition hover:bg-indigo-500">
                Log in
            </button>
        </form>
    </div>
</div>
</body>
</html>

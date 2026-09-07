<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset password — {{ config('app.name', 'QueryProxy') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen items-center justify-center bg-slate-900 font-sans antialiased">
<div class="w-full max-w-sm px-4">
    <div class="mb-8 text-center">
        <h1 class="text-2xl font-bold tracking-tight text-white">Choose a new password</h1>
    </div>

    <div class="rounded-xl bg-white p-6 shadow-xl">
        <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <div>
                <label for="email" class="mb-1 block text-sm font-medium text-slate-700">Email</label>
                <input id="email" name="email" type="email" required autocomplete="username"
                       value="{{ old('email', request('email')) }}"
                       class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                @error('email')
                    <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="password" class="mb-1 block text-sm font-medium text-slate-700">New password</label>
                <input id="password" name="password" type="password" required autocomplete="new-password"
                       class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                @error('password')
                    <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="password_confirmation" class="mb-1 block text-sm font-medium text-slate-700">New password (again)</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                       class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
            </div>
            <button type="submit"
                    class="w-full rounded-md bg-indigo-600 py-2 text-sm font-semibold text-white transition hover:bg-indigo-500">
                Reset password
            </button>
        </form>
    </div>
</div>
</body>
</html>

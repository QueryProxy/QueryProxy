<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot password — {{ config('app.name', 'QueryProxy') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen items-center justify-center bg-slate-900 font-sans antialiased">
<div class="w-full max-w-sm px-4">
    <div class="mb-8 text-center">
        <h1 class="text-2xl font-bold tracking-tight text-white">Forgot your password?</h1>
        <p class="mt-1 text-sm text-slate-400">We'll email you a reset link.</p>
    </div>

    <div class="rounded-xl bg-white p-6 shadow-xl">
        @if(session('status'))
            <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2.5 text-sm text-emerald-800">{{ session('status') }}</div>
        @endif

        <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
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
            <button type="submit"
                    class="w-full rounded-md bg-indigo-600 py-2 text-sm font-semibold text-white transition hover:bg-indigo-500">
                Send reset link
            </button>
            <p class="text-center text-sm">
                <a href="{{ route('login') }}" class="font-medium text-indigo-600 hover:text-indigo-500">Back to login</a>
            </p>
        </form>
    </div>
</div>
</body>
</html>

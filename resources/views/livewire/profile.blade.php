<div class="mx-auto max-w-xl">
    <div class="mb-6">
        <h1 class="text-2xl font-bold tracking-tight">Profile</h1>
        <p class="mt-1 text-sm text-slate-500">{{ auth()->user()->email }}</p>
    </div>

    @if(session('status'))
        <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif

    <div class="mb-6 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="mb-4 text-sm font-semibold text-slate-700">Name</h2>
        <form wire:submit="updateName" class="flex items-end gap-3">
            <div class="flex-1">
                <input type="text" wire:model="name" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                @error('name') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Save</button>
        </form>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="mb-4 text-sm font-semibold text-slate-700">Change password</h2>
        <form wire:submit="updatePassword" class="space-y-4">
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Current password</label>
                <input type="password" wire:model="currentPassword" autocomplete="current-password"
                       class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                @error('currentPassword') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">New password</label>
                <input type="password" wire:model="password" autocomplete="new-password"
                       class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                @error('password') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">New password (again)</label>
                <input type="password" wire:model="password_confirmation" autocomplete="new-password"
                       class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
            </div>
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Change Password</button>
        </form>
    </div>
</div>

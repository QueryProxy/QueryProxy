<div>
    <div class="mb-6">
        <a href="{{ route('admin.teams') }}" class="text-sm text-slate-500 hover:text-slate-700">&larr; Teams</a>
        <h1 class="mt-1 text-2xl font-bold tracking-tight">Users</h1>
        <p class="mt-1 text-sm text-slate-500">Create accounts, grant system-admin, or remove users. Team roles are managed per team.</p>
    </div>

    @if($generatedPassword)
        <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            Generated password (shown once): <code class="rounded bg-amber-100 px-1.5 py-0.5 font-mono">{{ $generatedPassword }}</code>
        </div>
    @endif

    <div class="mb-6 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <form wire:submit="createUser" class="grid grid-cols-1 gap-3 sm:grid-cols-5 sm:items-end">
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Name</label>
                <input type="text" wire:model="name" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                @error('name') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Email</label>
                <input type="email" wire:model="email" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                @error('email') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Password <span class="text-slate-400">(blank = generate)</span></label>
                <input type="text" wire:model="password" class="w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                @error('password') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
            </div>
            <label class="flex items-center gap-2 pb-2 text-sm text-slate-600">
                <input type="checkbox" wire:model="isAdmin" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                System admin
            </label>
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                Create User
            </button>
        </form>
    </div>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3">Name</th>
                    <th class="px-4 py-3">Email</th>
                    <th class="px-4 py-3">Teams</th>
                    <th class="px-4 py-3">Admin</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach($users as $u)
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $u->name }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ $u->email }}</td>
                        <td class="px-4 py-3">{{ $u->teams_count }}</td>
                        <td class="px-4 py-3">
                            @if($u->is_admin)
                                <span class="rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-700">Admin</span>
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            @if($u->id !== auth()->id())
                                <button wire:click="toggleAdmin({{ $u->id }})"
                                        class="font-medium text-indigo-600 hover:text-indigo-500">
                                    {{ $u->is_admin ? 'Revoke admin' : 'Make admin' }}
                                </button>
                                <button wire:click="deleteUser({{ $u->id }})"
                                        wire:confirm="Delete user {{ $u->email }}?"
                                        class="ml-3 font-medium text-rose-600 hover:text-rose-500">Delete</button>
                            @else
                                <span class="text-xs text-slate-400">you</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

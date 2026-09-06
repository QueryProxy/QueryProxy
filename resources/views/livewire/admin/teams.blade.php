<div>
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Teams</h1>
            <p class="mt-1 text-sm text-slate-500">Teams isolate connections, requests and audit trails.</p>
        </div>
        <div class="flex gap-2 text-sm">
            <a href="{{ route('admin.users') }}" class="rounded-md border border-slate-300 bg-white px-3 py-2 font-medium text-slate-700 hover:bg-slate-50">Manage Users</a>
        </div>
    </div>

    <div class="mb-6 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <form wire:submit="createTeam" class="flex items-end gap-3">
            <div class="flex-1">
                <label class="mb-1 block text-sm font-medium text-slate-700">New team name</label>
                <input type="text" wire:model="name" placeholder="e.g. Payments Squad"
                       class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                @error('name') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                Create Team
            </button>
        </form>
    </div>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3">Team</th>
                    <th class="px-4 py-3">Slug</th>
                    <th class="px-4 py-3">Members</th>
                    <th class="px-4 py-3">Created</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($teams as $team)
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $team->name }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ $team->slug }}</td>
                        <td class="px-4 py-3">{{ $team->users_count }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ $team->created_at->format('Y-m-d') }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.teams.members', $team) }}" class="font-medium text-indigo-600 hover:text-indigo-500">Members</a>
                            <button wire:click="deleteTeam({{ $team->id }})"
                                    wire:confirm="Delete team {{ $team->name }}? Connections and requests in it will be removed."
                                    class="ml-3 font-medium text-rose-600 hover:text-rose-500">Delete</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-slate-400">No teams yet — create the first one above.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

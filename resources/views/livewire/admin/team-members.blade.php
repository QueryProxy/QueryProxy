<div>
    <div class="mb-6">
        <a href="{{ route('admin.teams') }}" class="text-sm text-slate-500 hover:text-slate-700">&larr; Teams</a>
        <h1 class="mt-1 text-2xl font-bold tracking-tight">{{ $team->name }} — Members</h1>
    </div>

    <div class="mb-6 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <form wire:submit="addMember" class="flex items-end gap-3">
            <div class="flex-1">
                <label class="mb-1 block text-sm font-medium text-slate-700">User email</label>
                <input type="email" wire:model="email" placeholder="user@example.com"
                       class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                @error('email') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Role</label>
                <select wire:model="role" class="rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none">
                    @foreach($roles as $r)
                        <option value="{{ $r->value }}">{{ $r->label() }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                Add Member
            </button>
        </form>
    </div>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3">Name</th>
                    <th class="px-4 py-3">Email</th>
                    <th class="px-4 py-3">Role</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($members as $member)
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $member->name }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ $member->email }}</td>
                        <td class="px-4 py-3">
                            <select wire:change="updateRole({{ $member->id }}, $event.target.value)"
                                    class="rounded-md border border-slate-300 px-2 py-1 text-sm">
                                @foreach($roles as $r)
                                    <option value="{{ $r->value }}" @selected($member->pivot->role === $r->value)>{{ $r->label() }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button wire:click="removeMember({{ $member->id }})"
                                    wire:confirm="Remove {{ $member->name }} from {{ $team->name }}?"
                                    class="font-medium text-rose-600 hover:text-rose-500">Remove</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center text-slate-400">No members yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

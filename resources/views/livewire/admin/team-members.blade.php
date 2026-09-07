<div>
    <div class="mb-5">
        <a href="{{ route('admin.teams') }}" class="inline-flex items-center gap-1.5 font-mono text-[11.5px] text-mute-3 transition-colors hover:text-ink-3">
            <x-ui.icon name="arrow-left" :size="14" />
            Teams
        </a>
        <h1 class="mt-1.5 font-display text-[19px] font-semibold tracking-[-0.012em] text-ink">{{ $team->name }} — Members</h1>
    </div>

    <x-ui.panel padded class="mb-3.5">
        <form wire:submit="addMember" class="flex items-end gap-2.5">
            <x-ui.field label="User email" class="flex-1" :error="$errors->first('email')">
                <x-ui.input type="email" wire:model="email" placeholder="user@example.com" />
            </x-ui.field>

            <x-ui.field label="Role" class="w-40">
                <x-ui.select wire:model="role">
                    @foreach ($roles as $r)
                        <option value="{{ $r->value }}">{{ $r->label() }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            <x-ui.btn type="submit" variant="primary" icon="plus">Add member</x-ui.btn>
        </form>
    </x-ui.panel>

    <x-ui.panel>
        <x-ui.table>
            <x-slot:head>
                <tr>
                    <x-ui.th>Name</x-ui.th>
                    <x-ui.th class="w-64">Email</x-ui.th>
                    <x-ui.th class="w-44">Role</x-ui.th>
                    <x-ui.th class="w-28" align="right"></x-ui.th>
                </tr>
            </x-slot:head>

            @forelse ($members as $member)
                <x-ui.tr>
                    <x-ui.td class="text-ink-2">{{ $member->name }}</x-ui.td>
                    <x-ui.td mono muted>{{ $member->email }}</x-ui.td>
                    <x-ui.td>
                        <x-ui.select wire:change="updateRole({{ $member->id }}, $event.target.value)" class="h-[26px] text-[12px]">
                            @foreach ($roles as $r)
                                <option value="{{ $r->value }}" @selected($member->pivot->role === $r->value)>{{ $r->label() }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.td>
                    <x-ui.td align="right">
                        <x-ui.action wire:click="removeMember({{ $member->id }})"
                                     wire:confirm="Remove {{ $member->name }} from {{ $team->name }}?" tone="danger">Remove</x-ui.action>
                    </x-ui.td>
                </x-ui.tr>
            @empty
                <x-ui.empty :colspan="4">No members yet.</x-ui.empty>
            @endforelse
        </x-ui.table>
    </x-ui.panel>
</div>

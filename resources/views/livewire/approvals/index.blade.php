<div>
    <x-ui.page-header
        title="Pending Approvals"
        subtitle="Read every statement before you release it. Approving queues the job immediately; rejecting sends your reason back to the requester. You cannot approve your own requests." />

    @if (session('status'))
        <x-ui.alert tone="ok" icon="check" class="mb-3.5">{{ session('status') }}</x-ui.alert>
    @endif

    <div class="flex flex-col gap-3">
        @forelse ($requests as $request)
            @php $isOwnRequest = $request->user_id === auth()->id() && ! auth()->user()->isAdmin(); @endphp

            <x-ui.panel>
                <x-slot:header>
                    <a href="{{ route('requests.show', $request) }}" class="font-mono text-[12px] text-accent hover:brightness-125">#{{ $request->id }}</a>
                    @if ($request->title)
                        <span class="truncate text-[13px] font-medium text-ink">{{ $request->title }}</span>
                    @endif
                    <x-ui.badge :tone="$request->type->tone()">{{ $request->type->label() }}</x-ui.badge>
                    @if ($request->is_transaction)
                        <x-ui.badge tone="accent">transaction · {{ $request->statement_count }}</x-ui.badge>
                    @endif
                    <span class="ml-auto shrink-0 font-mono text-[11px] text-mute-3">
                        {{ $request->requester->name }} · {{ $request->connection->name }} · {{ $request->created_at->diffForHumans() }}
                    </span>
                </x-slot:header>

                <div class="p-3.5">
                    <x-ui.code>{{ $request->sql_prepared }}</x-ui.code>
                </div>

                <x-slot:footer>
                    @if ($isOwnRequest)
                        <span class="font-mono text-[11px] text-mute-3">Your own request — a second DBA has to decide.</span>
                    @elseif ($rejectingId === $request->id)
                        <form wire:submit="reject" class="flex w-full items-end gap-2.5">
                            <x-ui.field label="Rejection reason" class="flex-1" :error="$errors->first('rejectionReason')">
                                <x-ui.input type="text" wire:model="rejectionReason" autofocus
                                            placeholder="e.g. add a team_id filter and a batch size" />
                            </x-ui.field>
                            <x-ui.btn type="submit" variant="danger">Confirm rejection</x-ui.btn>
                            <x-ui.btn type="button" wire:click="$set('rejectingId', null)">Cancel</x-ui.btn>
                        </form>
                    @else
                        <span class="font-mono text-[11px] text-mute-3">Decisions are written to the audit log with your identity.</span>
                        <x-ui.btn wire:click="startReject({{ $request->id }})" variant="danger" icon="x" class="ml-auto">Reject</x-ui.btn>
                        <x-ui.btn wire:click="approve({{ $request->id }})" wire:confirm="Approve and execute request #{{ $request->id }}?"
                                  variant="ok" icon="check">Approve &amp; execute</x-ui.btn>
                    @endif
                </x-slot:footer>
            </x-ui.panel>
        @empty
            <x-ui.panel>
                <x-ui.empty>Nothing waiting for approval.</x-ui.empty>
            </x-ui.panel>
        @endforelse
    </div>

    @if ($requests->hasPages())
        <div class="mt-3.5">{{ $requests->links() }}</div>
    @endif
</div>

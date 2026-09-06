<div>
    <div class="mb-6">
        <h1 class="text-2xl font-bold tracking-tight">Pending Approvals</h1>
        <p class="mt-1 text-sm text-slate-500">Review and decide on submitted query requests. You cannot approve your own requests.</p>
    </div>

    @if(session('status'))
        <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif

    <div class="space-y-4">
        @forelse($requests as $request)
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-3 flex items-start justify-between gap-4">
                    <div>
                        <a href="{{ route('requests.show', $request) }}" class="font-semibold text-indigo-600 hover:text-indigo-500">
                            #{{ $request->id }} {{ $request->title ? '— '.$request->title : '' }}
                        </a>
                        <p class="mt-0.5 text-sm text-slate-500">
                            {{ $request->requester->name }} · {{ $request->connection->name }} ·
                            <span class="font-medium uppercase {{ $request->type->value === 'read' ? 'text-sky-600' : 'text-orange-600' }}">{{ $request->type->value }}</span>
                            @if($request->is_transaction) · transaction ({{ $request->statement_count }} statements) @endif
                            · {{ $request->created_at->diffForHumans() }}
                        </p>
                    </div>
                    <div class="flex shrink-0 gap-2">
                        @if($request->user_id === auth()->id() && !auth()->user()->isAdmin())
                            <span class="rounded-md bg-slate-100 px-3 py-2 text-sm text-slate-500">Your own request</span>
                        @else
                            <button wire:click="approve({{ $request->id }})" wire:confirm="Approve and execute request #{{ $request->id }}?"
                                    class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Approve</button>
                            <button wire:click="startReject({{ $request->id }})"
                                    class="rounded-md bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-500">Reject</button>
                        @endif
                    </div>
                </div>
                <pre class="overflow-x-auto rounded-md bg-slate-50 p-3 font-mono text-xs leading-5 text-slate-800">{{ $request->sql_prepared }}</pre>

                @if($rejectingId === $request->id)
                    <form wire:submit="reject" class="mt-3 flex items-end gap-3">
                        <div class="flex-1">
                            <label class="mb-1 block text-sm font-medium text-slate-700">Rejection reason</label>
                            <input type="text" wire:model="rejectionReason" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" autofocus>
                            @error('rejectionReason') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit" class="rounded-md bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-500">Confirm</button>
                        <button type="button" wire:click="$set('rejectingId', null)" class="rounded-md border border-slate-300 px-4 py-2 text-sm">Cancel</button>
                    </form>
                @endif
            </div>
        @empty
            <div class="rounded-xl border border-slate-200 bg-white px-4 py-10 text-center text-slate-400 shadow-sm">
                Nothing waiting for approval. 🎉
            </div>
        @endforelse
    </div>

    <div class="mt-4">{{ $requests->links() }}</div>
</div>

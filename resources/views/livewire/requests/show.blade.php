<div>
    <div class="mb-6 flex items-start justify-between">
        <div>
            <a href="{{ route('requests.index') }}" class="text-sm text-slate-500 hover:text-slate-700">&larr; Requests</a>
            <h1 class="mt-1 flex items-center gap-3 text-2xl font-bold tracking-tight">
                Request #{{ $request->id }}
                <span class="rounded px-2 py-0.5 text-sm font-medium {{ $request->status->badgeClasses() }}">{{ $request->status->label() }}</span>
                <span class="rounded px-1.5 py-0.5 text-xs font-bold uppercase {{ $request->type->value === 'read' ? 'bg-sky-100 text-sky-700' : 'bg-orange-100 text-orange-700' }}">{{ $request->type->value }}</span>
                @if($request->is_transaction)
                    <span class="rounded bg-violet-100 px-1.5 py-0.5 text-xs font-medium text-violet-700">transaction</span>
                @endif
            </h1>
            @if($request->title)
                <p class="mt-1 text-slate-600">{{ $request->title }}</p>
            @endif
        </div>

        <div class="flex gap-2">
            @if($canCancel)
                <button wire:click="cancel" wire:confirm="Cancel this request?"
                        class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    Cancel Request
                </button>
            @endif
            @if($canReview)
                <button wire:click="approve" wire:confirm="Approve and execute this query?"
                        class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">
                    Approve
                </button>
                <button wire:click="$set('showRejectForm', true)"
                        class="rounded-md bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-500">
                    Reject
                </button>
            @endif
        </div>
    </div>

    @if($showRejectForm)
        <div class="mb-6 rounded-xl border border-rose-200 bg-white p-5 shadow-sm">
            <form wire:submit="reject" class="flex items-end gap-3">
                <div class="flex-1">
                    <label class="mb-1 block text-sm font-medium text-slate-700">Rejection reason</label>
                    <input type="text" wire:model="rejectionReason" placeholder="Why is this request being rejected?"
                           class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                    @error('rejectionReason') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="rounded-md bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-500">Confirm Rejection</button>
                <button type="button" wire:click="$set('showRejectForm', false)" class="rounded-md border border-slate-300 px-4 py-2 text-sm text-slate-700">Cancel</button>
            </form>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-5 py-3 text-sm font-semibold text-slate-700">SQL to execute</div>
                <pre class="overflow-x-auto p-5 font-mono text-sm leading-6 text-slate-800">{{ $request->sql_prepared }}</pre>
            </div>

            @if($request->sql_prepared !== $request->sql_original)
                <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-5 py-3 text-sm font-semibold text-slate-500">
                        As submitted <span class="ml-1 font-normal text-slate-400">(guards modified the SQL above)</span>
                    </div>
                    <pre class="overflow-x-auto p-5 font-mono text-sm leading-6 text-slate-500">{{ $request->sql_original }}</pre>
                </div>
            @endif

            @if($request->error_message)
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-5">
                    <p class="mb-1 text-sm font-semibold text-rose-800">Execution error</p>
                    <pre class="overflow-x-auto font-mono text-xs text-rose-700">{{ $request->error_message }}</pre>
                </div>
            @endif

            @if($request->rejection_reason)
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-5 text-sm text-rose-800">
                    <span class="font-semibold">Rejected:</span> {{ $request->rejection_reason }}
                </div>
            @endif

            @if($request->hasResult())
                <livewire:results.viewer :query-request="$request" :key="'result-'.$request->id" />
            @endif
        </div>

        <div class="space-y-4">
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="mb-3 text-sm font-semibold text-slate-700">Details</h3>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500">Connection</dt><dd class="font-medium">{{ $request->connection->name }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Driver</dt><dd>{{ $request->connection->driver->label() }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Requester</dt><dd>{{ $request->requester->name }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Submitted</dt><dd title="{{ $request->created_at }}">{{ $request->created_at->diffForHumans() }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Statements</dt><dd>{{ $request->statement_count }}</dd></div>
                    @if($request->reviewer)
                        <div class="flex justify-between"><dt class="text-slate-500">Reviewed by</dt><dd>{{ $request->reviewer->name }} <span class="text-xs text-slate-400">({{ $request->review_channel }})</span></dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Reviewed</dt><dd>{{ $request->reviewed_at?->diffForHumans() }}</dd></div>
                    @endif
                    @if($request->executed_at)
                        <div class="flex justify-between"><dt class="text-slate-500">Executed</dt><dd>{{ $request->executed_at->diffForHumans() }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Duration</dt><dd>{{ $request->duration_ms }} ms</dd></div>
                    @endif
                    @if($request->affected_rows !== null)
                        <div class="flex justify-between"><dt class="text-slate-500">Affected rows</dt><dd>{{ $request->affected_rows }}</dd></div>
                    @endif
                    @if($request->result_row_count !== null)
                        <div class="flex justify-between"><dt class="text-slate-500">Result rows</dt><dd>{{ $request->result_row_count }}</dd></div>
                    @endif
                </dl>
            </div>

            @if($request->isPending())
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                    Waiting for a DBA to approve. This page reflects the latest state on refresh.
                </div>
            @endif
        </div>
    </div>

    @if(in_array($request->status->value, ['queued', 'running'], true))
        <div wire:poll.3s="$refresh"></div>
    @endif
</div>

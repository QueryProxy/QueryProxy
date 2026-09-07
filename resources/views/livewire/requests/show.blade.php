<div>
    <div class="mb-5 flex items-start gap-4">
        <div class="min-w-0">
            <a href="{{ route('requests.index') }}" class="inline-flex items-center gap-1.5 font-mono text-[11.5px] text-mute-3 transition-colors hover:text-ink-3">
                <x-ui.icon name="arrow-left" :size="14" />
                Requests
            </a>
            <h1 class="mt-1.5 flex flex-wrap items-center gap-2.5 font-display text-[19px] font-semibold tracking-[-0.012em] text-ink">
                Request #{{ $request->id }}
                <x-ui.badge :tone="$request->status->tone()">{{ $request->status->label() }}</x-ui.badge>
                <x-ui.badge :tone="$request->type->tone()">{{ $request->type->label() }}</x-ui.badge>
                @if ($request->is_transaction)
                    <x-ui.badge tone="accent">transaction</x-ui.badge>
                @endif
            </h1>
            @if ($request->title)
                <p class="mt-1.5 text-[12.5px] text-mute">{{ $request->title }}</p>
            @endif
        </div>

        <div class="ml-auto flex shrink-0 items-center gap-2">
            @if ($canCancel)
                <x-ui.btn wire:click="cancel" wire:confirm="Cancel this request?">Cancel request</x-ui.btn>
            @endif
            @if ($canReview)
                <x-ui.btn wire:click="approve" wire:confirm="Approve and execute this query?" variant="ok" icon="check">Approve</x-ui.btn>
                <x-ui.btn wire:click="$set('showRejectForm', true)" variant="danger" icon="x">Reject</x-ui.btn>
            @endif
        </div>
    </div>

    @if ($showRejectForm)
        <x-ui.panel padded class="mb-4 border-danger-line">
            <form wire:submit="reject" class="flex items-end gap-2.5">
                <x-ui.field label="Rejection reason" class="flex-1" :error="$errors->first('rejectionReason')">
                    <x-ui.input type="text" wire:model="rejectionReason" placeholder="Why is this request being rejected?" />
                </x-ui.field>
                <x-ui.btn type="submit" variant="danger">Confirm rejection</x-ui.btn>
                <x-ui.btn type="button" wire:click="$set('showRejectForm', false)">Cancel</x-ui.btn>
            </form>
        </x-ui.panel>
    @endif

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="flex flex-col gap-4 lg:col-span-2">
            <x-ui.panel title="SQL to execute">
                <x-ui.code class="rounded-none border-0 bg-canvas">{{ $request->sql_prepared }}</x-ui.code>
            </x-ui.panel>

            @if ($request->sql_prepared !== $request->sql_original)
                <x-ui.panel>
                    <x-slot:header>
                        <span class="font-display text-[11.5px] font-semibold uppercase tracking-[0.07em] text-ink-4">As submitted</span>
                        <span class="font-mono text-[11px] text-mute-3">guards modified the SQL above</span>
                    </x-slot:header>
                    <x-ui.code class="rounded-none border-0 bg-canvas text-mute">{{ $request->sql_original }}</x-ui.code>
                </x-ui.panel>
            @endif

            @if ($request->error_message)
                <x-ui.panel padded class="border-danger-line bg-danger-bg">
                    <p class="eyebrow mb-1.5 text-danger">Execution error</p>
                    <pre class="overflow-x-auto font-mono text-[11.5px] leading-[1.7] text-danger">{{ $request->error_message }}</pre>
                </x-ui.panel>
            @endif

            @if ($request->rejection_reason)
                <x-ui.alert tone="danger" icon="x">
                    <span class="font-medium">Rejected:</span> {{ $request->rejection_reason }}
                </x-ui.alert>
            @endif

            @if ($request->hasResult())
                <livewire:results.viewer :query-request="$request" :key="'result-'.$request->id" />
            @endif
        </div>

        <div class="flex flex-col gap-4">
            <x-ui.panel title="Details" padded>
                <dl class="divide-y divide-line">
                    <x-ui.kv label="Connection">{{ $request->connection->name }}</x-ui.kv>
                    <x-ui.kv label="Driver">{{ $request->connection->driver->label() }}</x-ui.kv>
                    <x-ui.kv label="Requester">{{ $request->requester->name }}</x-ui.kv>
                    <x-ui.kv label="Submitted">{{ $request->created_at->diffForHumans() }}</x-ui.kv>
                    <x-ui.kv label="Statements">{{ $request->statement_count }}</x-ui.kv>

                    @if ($request->reviewer)
                        <x-ui.kv label="Reviewed by" :hint="$request->review_channel">{{ $request->reviewer->name }}</x-ui.kv>
                        <x-ui.kv label="Reviewed">{{ $request->reviewed_at?->diffForHumans() }}</x-ui.kv>
                    @endif

                    @if ($request->executed_at)
                        <x-ui.kv label="Executed">{{ $request->executed_at->diffForHumans() }}</x-ui.kv>
                        <x-ui.kv label="Duration">{{ $request->duration_ms }} ms</x-ui.kv>
                    @endif

                    @if ($request->affected_rows !== null)
                        <x-ui.kv label="Affected rows">{{ $request->affected_rows }}</x-ui.kv>
                    @endif

                    @if ($request->result_row_count !== null)
                        <x-ui.kv label="Result rows">{{ $request->result_row_count }}</x-ui.kv>
                    @endif
                </dl>
            </x-ui.panel>

            @if ($request->isPending())
                <x-ui.alert tone="pending" icon="clock">
                    Waiting for a DBA to approve. This page reflects the latest state on refresh.
                </x-ui.alert>
            @endif
        </div>
    </div>

    @if (in_array($request->status->value, ['queued', 'running'], true))
        <div wire:poll.3s="$refresh"></div>
    @endif
</div>

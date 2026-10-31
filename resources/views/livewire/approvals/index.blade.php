<div>
    <x-ui.page-header
        title="Pending Approvals"
        subtitle="Read every statement before you release it. Approving queues the job immediately; rejecting sends your reason back to the requester. You cannot approve your own requests." />

    @if (session('status'))
        <x-ui.alert tone="ok" icon="check" class="mb-3.5">{{ session('status') }}</x-ui.alert>
    @endif

    <nav aria-label="Approval queues" class="mb-3.5 flex flex-wrap gap-2">
        <x-ui.chip :active="$tab === 'queries'" wire:click="showTab('queries')" :aria-current="$tab === 'queries' ? 'true' : null">
            Query requests · {{ $requests->total() }}
        </x-ui.chip>
        <x-ui.chip :active="$tab === 'access'" wire:click="showTab('access')" :aria-current="$tab === 'access' ? 'true' : null">
            Access requests · {{ $grantRequests->total() }}
        </x-ui.chip>
    </nav>

    @if ($tab === 'access')
    <div class="flex flex-col gap-3">
        @error('grant')
            <x-ui.alert tone="danger" icon="alert" role="alert">{{ $message }}</x-ui.alert>
        @enderror

        @forelse ($grantRequests as $grantRequest)
            @php
                $isOwnGrantRequest = $grantRequest->user_id === auth()->id();
                $heldGrant = $currentGrants->get($grantRequest->connection_id.':'.$grantRequest->user_id);
                // ADR-025: another level, or a permanent grant, leaves nothing this request may change.
                $blockedByGrant = $heldGrant && ($heldGrant->access_level !== $grantRequest->access_level || $heldGrant->isPermanent());
            @endphp

            <x-ui.panel wire:key="grant-request-{{ $grantRequest->id }}">
                <x-slot:header>
                    <span class="font-mono text-[12px] text-mute-3">#{{ $grantRequest->id }}</span>
                    <span class="truncate text-[13px] font-medium text-ink">{{ $grantRequest->connection->name }}</span>
                    <x-ui.badge :tone="$grantRequest->access_level->value">{{ $grantRequest->access_level->label() }}</x-ui.badge>
                    <x-ui.badge tone="accent">{{ $grantRequest->durationLabel() }}</x-ui.badge>
                    <span class="ml-auto shrink-0 font-mono text-[11px] text-mute-3">
                        {{ $grantRequest->requester->name }} · {{ $grantRequest->created_at->diffForHumans() }}
                    </span>
                </x-slot:header>

                <div class="flex flex-col gap-2.5 p-3.5">
                    <div>
                        <div class="eyebrow mb-1.5">Requester's reason</div>
                        <p class="whitespace-pre-line break-words text-[12.5px] text-ink-3">{{ $grantRequest->reason }}</p>
                    </div>

                    @if ($heldGrant)
                        <x-ui.alert tone="pending" icon="clock">
                            {{ $grantRequest->requester->name }} already has {{ $heldGrant->describe() }}.
                            @if ($heldGrant->access_level !== $grantRequest->access_level)
                                This request asks for another level, so it cannot be approved. Reject it, and change the existing grant on the Connections page if the new level is needed.
                            @elseif ($heldGrant->isPermanent())
                                The request adds nothing to a permanent grant, so it cannot be approved. Reject it.
                            @else
                                Approving only extends the end time; it never shortens a grant or makes it permanent.
                            @endif
                        </x-ui.alert>
                    @else
                        <p class="text-[12px] text-mute-3">
                            Approving gives {{ $grantRequest->access_level->value }} access for {{ $grantRequest->durationLabel() }}, starting now. Every query still needs its own approval.
                        </p>
                    @endif
                </div>

                <x-slot:footer>
                    @if ($isOwnGrantRequest)
                        <span class="font-mono text-[11px] text-mute-3">Your own request — another DBA has to decide.</span>
                    @elseif ($rejectingGrantId === $grantRequest->id)
                        <form wire:submit="rejectGrant" class="flex w-full flex-wrap items-end gap-2.5">
                            <x-ui.field label="Rejection reason" for="grant-rejection-{{ $grantRequest->id }}" class="min-w-[14rem] flex-1" :error="$errors->first('grantRejectionNote')">
                                <x-ui.input id="grant-rejection-{{ $grantRequest->id }}" type="text" wire:model="grantRejectionNote" autofocus maxlength="1000"
                                            placeholder="e.g. use the read-only replica instead" />
                            </x-ui.field>
                            <x-ui.btn type="submit" variant="danger">Confirm rejection</x-ui.btn>
                            <x-ui.btn type="button" wire:click="$set('rejectingGrantId', null)">Cancel</x-ui.btn>
                        </form>
                    @else
                        <span class="hidden font-mono text-[11px] text-mute-3 sm:inline">Decisions are written to the audit log with your identity.</span>
                        <x-ui.btn wire:click="startRejectGrant({{ $grantRequest->id }})" variant="danger" icon="x" class="ml-auto">Reject</x-ui.btn>
                        @unless ($blockedByGrant)
                            <x-ui.btn wire:click="approveGrant({{ $grantRequest->id }})"
                                      wire:confirm="Give {{ $grantRequest->requester->name }} {{ $grantRequest->access_level->value }} access to {{ $grantRequest->connection->name }} for {{ $grantRequest->durationLabel() }}?"
                                      variant="ok" icon="check">Approve access</x-ui.btn>
                        @endunless
                    @endif
                </x-slot:footer>
            </x-ui.panel>
        @empty
            <x-ui.panel>
                <x-ui.empty>No access requests waiting.</x-ui.empty>
            </x-ui.panel>
        @endforelse

        @if ($grantRequests->hasPages())
            <div class="mt-0.5">{{ $grantRequests->links() }}</div>
        @endif
    </div>
    @else
    <div class="flex flex-col gap-3">
        @forelse ($requests as $request)
            @php
                $isOwnRequest = $request->user_id === auth()->id() && ! auth()->user()->isAdmin();
                $sensitiveFilterColumns = $request->sensitiveFilterColumns();
            @endphp

            <x-ui.panel>
                <x-slot:header>
                    <a href="{{ route('requests.show', $request) }}" class="font-mono text-[12px] text-accent hover:brightness-125">#{{ $request->id }}</a>
                    @if ($request->title)
                        <span class="truncate text-[13px] font-medium text-ink">{{ $request->title }}</span>
                    @endif
                    <x-ui.badge :tone="$request->type->tone()">{{ $request->type->label() }}</x-ui.badge>
                    @if ($request->is_ddl)
                        <x-ui.badge tone="danger">DDL</x-ui.badge>
                    @endif
                    @if ($request->is_transaction)
                        <x-ui.badge tone="accent">transaction · {{ $request->statement_count }}</x-ui.badge>
                    @endif
                    <x-ui.sensitive-filter :columns="$sensitiveFilterColumns" />
                    <span class="ml-auto shrink-0 font-mono text-[11px] text-mute-3">
                        {{ $request->requester->name }} · {{ $request->connection->name }} · {{ $request->created_at->diffForHumans() }}
                    </span>
                </x-slot:header>

                <div class="flex flex-col gap-2.5 p-3.5">
                    <x-ui.sensitive-filter :columns="$sensitiveFilterColumns" variant="alert" />
                    <x-ui.code>{{ $request->sql_prepared }}</x-ui.code>
                </div>

                @if ($request->note)
                    <div class="border-t border-line px-3.5 py-3">
                        <div class="eyebrow mb-1.5">Requester's reason</div>
                        <p class="whitespace-pre-line text-[12.5px] text-ink-3">{{ $request->note }}</p>
                    </div>
                @endif

                <x-slot:footer>
                    @if ($isOwnRequest)
                        <span class="font-mono text-[11px] text-mute-3">Your own request — a second DBA has to decide.</span>
                    @elseif ($overridingId === $request->id)
                        <form wire:submit="approve({{ $request->id }})" class="flex w-full items-end gap-2.5">
                            <x-ui.field label="Override reason" hint="required — written to the audit log" class="flex-1" :error="$errors->first('reason')">
                                <x-ui.input type="text" wire:model="reason" autofocus
                                            placeholder="Why does this need approving outside the normal DBA review?" />
                            </x-ui.field>
                            <x-ui.btn type="submit" variant="ok" icon="check">Approve by override</x-ui.btn>
                            <x-ui.btn type="button" wire:click="$set('overridingId', null)">Cancel</x-ui.btn>
                        </form>
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
                        @if (in_array($request->id, $overrideIds, true))
                            <x-ui.btn wire:click="startOverride({{ $request->id }})" variant="ok" icon="check">Approve (override)…</x-ui.btn>
                        @else
                            <x-ui.btn wire:click="approve({{ $request->id }})" wire:confirm="Approve and execute request #{{ $request->id }}?"
                                      variant="ok" icon="check">Approve &amp; execute</x-ui.btn>
                        @endif
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
    @endif
</div>

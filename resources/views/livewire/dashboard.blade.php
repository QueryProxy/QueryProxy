<div>
    <x-ui.page-header title="Dashboard">
        <p class="mt-1.5 text-[12.5px] leading-[1.55] text-mute">
            @if ($team)
                Queue health for <span class="font-medium text-ink-3">{{ $team->name }}</span>. Every number below is scoped to this team.
            @else
                You are not a member of any team yet. Ask an administrator to add you.
            @endif
        </p>
    </x-ui.page-header>

    @if (session('status'))
        <x-ui.alert tone="ok" icon="check" class="mb-3.5">{{ session('status') }}</x-ui.alert>
    @endif

    @if ($team)
        @php
            $tiles = [
                ['key' => 'pending', 'label' => 'Pending approval', 'tone' => 'pending', 'value' => $counts['pending'] ?? 0],
                ['key' => 'active', 'label' => 'Queued / running', 'tone' => 'running', 'value' => ($counts['queued'] ?? 0) + ($counts['running'] ?? 0)],
                ['key' => 'completed', 'label' => 'Completed', 'tone' => 'ok', 'value' => $counts['completed'] ?? 0],
                ['key' => 'failed', 'label' => 'Failed / rejected', 'tone' => 'danger', 'value' => ($counts['failed'] ?? 0) + ($counts['rejected'] ?? 0)],
            ];
        @endphp

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach ($tiles as $tile)
                <x-ui.stat
                    :label="$tile['label']"
                    :value="$tile['value']"
                    :tone="$tile['tone']"
                    :active="$filter === $tile['key']"
                    wire:click="filterBy('{{ $tile['key'] }}')"
                    class="cursor-pointer" />
            @endforeach
        </div>

        <div class="mt-4 grid grid-cols-1 items-start gap-4 xl:grid-cols-[minmax(0,1.75fr)_minmax(0,1fr)]">
            <x-ui.panel>
                <x-slot:header>
                    <span class="font-display text-[11.5px] font-semibold uppercase tracking-[0.07em] text-ink-4">Recent requests</span>
                    @if ($filter !== '')
                        <x-ui.badge tone="accent">filtered</x-ui.badge>
                    @endif
                    <x-ui.action :href="route('requests.index')" tone="accent" class="ml-auto">View all</x-ui.action>
                </x-slot:header>

                <x-ui.table>
                    <x-slot:head>
                        <tr>
                            <x-ui.th class="w-14">Req</x-ui.th>
                            <x-ui.th>Title / SQL</x-ui.th>
                            <x-ui.th class="w-32">Requester</x-ui.th>
                            <x-ui.th class="w-16">Type</x-ui.th>
                            <x-ui.th class="w-28">Status</x-ui.th>
                            <x-ui.th class="w-24" align="right">Submitted</x-ui.th>
                        </tr>
                    </x-slot:head>

                    @forelse ($requests as $request)
                        <x-ui.tr>
                            <x-ui.td mono>
                                <a href="{{ route('requests.show', $request) }}" class="text-accent hover:brightness-125">#{{ $request->id }}</a>
                            </x-ui.td>
                            <x-ui.td truncate>
                                <a href="{{ route('requests.show', $request) }}" class="block truncate text-ink-2 hover:text-accent">
                                    @if ($request->title)
                                        {{ $request->title }}
                                    @elseif (auth()->user()->can('viewRawSql', $request))
                                        {{ str($request->sql_original)->limit(60) }}
                                    @else
                                        {{ str($request->redactedSql('sql_original') ?? '(SQL hidden)')->limit(60) }}
                                    @endif
                                </a>
                            </x-ui.td>
                            <x-ui.td muted nowrap>{{ $request->requester->name }}</x-ui.td>
                            <x-ui.td><x-ui.badge :tone="$request->type->tone()">{{ $request->type->label() }}</x-ui.badge></x-ui.td>
                            <x-ui.td><x-ui.badge :tone="$request->status->tone()">{{ $request->status->label() }}</x-ui.badge></x-ui.td>
                            <x-ui.td mono muted nowrap align="right" title="{{ $request->created_at }}">{{ $request->created_at->diffForHumans() }}</x-ui.td>
                        </x-ui.tr>
                    @empty
                        <x-ui.empty :colspan="6">
                            {{ $filter === '' ? 'No requests yet.' : 'No requests with that status.' }}
                        </x-ui.empty>
                    @endforelse
                </x-ui.table>
            </x-ui.panel>

            <div class="flex flex-col gap-4">
                @if ($canDecide)
                    <x-ui.panel>
                        <x-slot:header>
                            <span class="font-display text-[11.5px] font-semibold uppercase tracking-[0.07em] text-ink-4">Awaiting your decision</span>
                            @if ($awaiting->isNotEmpty())
                                <x-ui.badge tone="pending" class="ml-auto">{{ $awaiting->count() }} open</x-ui.badge>
                            @endif
                        </x-slot:header>

                        @forelse ($awaiting as $request)
                            <div class="border-b border-line p-3.5 last:border-b-0">
                                <div class="mb-2 flex items-center gap-2">
                                    <a href="{{ route('requests.show', $request) }}" class="font-mono text-[12px] text-accent hover:brightness-125">#{{ $request->id }}</a>
                                    <x-ui.badge :tone="$request->type->tone()">{{ $request->type->label() }}</x-ui.badge>
                                    <span class="ml-auto font-mono text-[10.5px] text-mute-4">{{ $request->created_at->diffForHumans() }}</span>
                                </div>

                                @if ($request->title)
                                    <p class="mb-1 truncate text-[12.5px] font-medium text-ink-2">{{ $request->title }}</p>
                                @endif
                                <p class="mb-2.5 font-mono text-[10.5px] text-mute-3">{{ $request->requester->name }} · {{ $request->connection->name }}</p>

                                <x-ui.code class="mb-2.5 max-h-24 overflow-y-auto whitespace-pre-wrap">{{ $request->sql_prepared }}</x-ui.code>

                                <div class="flex gap-2">
                                    <x-ui.btn :href="route('requests.show', $request)" size="sm" class="flex-1">Review</x-ui.btn>
                                    <x-ui.btn wire:click="approve({{ $request->id }})"
                                              wire:confirm="Approve and execute request #{{ $request->id }}?"
                                              variant="ok" size="sm" icon="check" class="flex-1">Approve</x-ui.btn>
                                </div>
                            </div>
                        @empty
                            <x-ui.empty>Nothing waiting on you.</x-ui.empty>
                        @endforelse
                    </x-ui.panel>
                @endif

                <x-ui.panel>
                    <x-slot:header>
                        <span class="font-display text-[11.5px] font-semibold uppercase tracking-[0.07em] text-ink-4">
                            {{ $canDecide ? 'Connections' : 'Your connections' }}
                        </span>
                        @if ($canDecide)
                            <x-ui.action :href="route('connections.index')" tone="accent" class="ml-auto">Manage</x-ui.action>
                        @endif
                    </x-slot:header>

                    @forelse ($connections as $connection)
                        <div class="flex items-center gap-2.5 border-b border-line px-3.5 py-2.5 last:border-b-0">
                            <x-ui.icon name="database" :size="14" class="text-mute-3" />
                            <span class="truncate font-mono text-[12px] text-ink-2">{{ $connection->name }}</span>
                            <x-ui.badge class="ml-auto">{{ $connection->driver->label() }}</x-ui.badge>
                            @if ($canDecide)
                                <span class="font-mono text-[10.5px] text-mute-4">{{ $connection->granted_users_count }} granted</span>
                            @elseif ($access && ($myGrant = $access['grants']->get($connection->id)))
                                <x-ui.badge :tone="$myGrant->access_level->value">{{ $myGrant->access_level->label() }}</x-ui.badge>
                                <span class="font-mono text-[10.5px] text-mute-4"
                                      @unless ($myGrant->isPermanent()) title="until {{ $myGrant->expires_at->copy()->utc()->format('Y-m-d H:i') }} UTC" @endunless>
                                    {{ $myGrant->isPermanent() ? 'permanent' : $myGrant->remaining().' left' }}
                                </span>
                            @endif
                        </div>
                    @empty
                        <x-ui.empty>
                            {{ $canDecide ? 'No connections yet.' : 'No connection has been granted to you yet.' }}
                        </x-ui.empty>
                    @endforelse
                </x-ui.panel>

                @if ($access)
                    <x-ui.panel>
                        <x-slot:header>
                            <span class="font-display text-[11.5px] font-semibold uppercase tracking-[0.07em] text-ink-4">Request access</span>
                            @if ($access['pending']->isNotEmpty())
                                <x-ui.badge tone="pending" class="ml-auto">{{ $access['pending']->count() }} pending</x-ui.badge>
                            @endif
                        </x-slot:header>

                        <p class="border-b border-line px-3.5 py-2.5 text-[12px] leading-[1.5] text-mute-3">
                            Ask a DBA of your team for access to a connection for a limited time. Access ends on its own; every query you send still needs its own approval.
                        </p>

                        @error('request')
                            <div class="border-b border-line p-3.5">
                                <x-ui.alert tone="danger" icon="alert" role="alert">{{ $message }}</x-ui.alert>
                            </div>
                        @enderror

                        @php
                            $pendingByConnection = $access['pending']->keyBy('connection_id');
                        @endphp

                        @forelse ($access['requestable'] as $connection)
                            @php
                                $myGrant = $access['grants']->get($connection->id);
                                $openRequest = $pendingByConnection->get($connection->id);
                            @endphp

                            <div class="border-b border-line last:border-b-0">
                                <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1.5 px-3.5 py-2.5">
                                    <x-ui.icon name="database" :size="14" class="text-mute-3" />
                                    <span class="min-w-0 truncate font-mono text-[12px] text-ink-2">{{ $connection->name }}</span>

                                    @if ($myGrant)
                                        <x-ui.badge :tone="$myGrant->access_level->value">{{ $myGrant->access_level->label() }}</x-ui.badge>
                                        <span class="font-mono text-[10.5px] text-mute-4"
                                              title="until {{ $myGrant->expires_at->copy()->utc()->format('Y-m-d H:i') }} UTC">
                                            {{ $myGrant->remaining() }} left
                                        </span>
                                    @endif

                                    <span class="ml-auto">
                                        @if ($openRequest)
                                            <x-ui.badge tone="pending">Request pending</x-ui.badge>
                                        @elseif ($requestingId !== $connection->id)
                                            <x-ui.action wire:click="startAccessRequest({{ $connection->id }})" tone="accent"
                                                         aria-label="{{ $myGrant ? 'Extend' : 'Request' }} access to {{ $connection->name }}">
                                                {{ $myGrant ? 'Extend access' : 'Request access' }}
                                            </x-ui.action>
                                        @endif
                                    </span>
                                </div>

                                @if ($requestingId === $connection->id)
                                    <form wire:submit="requestAccess" class="flex flex-col gap-3 border-t border-line bg-canvas px-3.5 py-3.5"
                                          aria-label="Request access to {{ $connection->name }}">
                                        @if ($myGrant)
                                            <p class="text-[12px] leading-[1.5] text-mute-3">
                                                You already have {{ $myGrant->access_level->value }} access. An approval can only extend it, never shorten it or change its level.
                                            </p>
                                        @endif

                                        <x-ui.field label="Access level" for="request-level" :error="$errors->first('requestLevel')">
                                            <x-ui.select id="request-level" wire:model="requestLevel" :disabled="(bool) $myGrant">
                                                @foreach (\App\Enums\GrantAccessLevel::cases() as $level)
                                                    <option value="{{ $level->value }}">{{ $level->label() }}{{ $level->allowsWrite() ? ' (writes and DDL, each request still approved)' : ' (SELECT only)' }}</option>
                                                @endforeach
                                            </x-ui.select>
                                        </x-ui.field>

                                        <x-ui.field label="For how long" for="request-duration" :error="$errors->first('requestDuration')">
                                            <x-ui.select id="request-duration" wire:model.live="requestDuration">
                                                @foreach ($durationPresets as $minutes => $label)
                                                    <option value="{{ $minutes }}">{{ $label }}</option>
                                                @endforeach
                                                <option value="custom">Custom…</option>
                                            </x-ui.select>
                                        </x-ui.field>

                                        @if ($requestDuration === 'custom')
                                            <div class="flex gap-2.5">
                                                <x-ui.field label="Amount" for="request-custom-amount" class="w-24 shrink-0">
                                                    <x-ui.input id="request-custom-amount" type="number" min="1" step="1" inputmode="numeric" wire:model="requestCustomAmount" />
                                                </x-ui.field>
                                                <x-ui.field label="Unit" for="request-custom-unit" class="flex-1">
                                                    <x-ui.select id="request-custom-unit" wire:model="requestCustomUnit">
                                                        @foreach ($durationUnits as $unit)
                                                            <option value="{{ $unit }}">{{ ucfirst($unit) }}</option>
                                                        @endforeach
                                                    </x-ui.select>
                                                </x-ui.field>
                                            </div>
                                        @endif

                                        <x-ui.field label="Reason" hint="required" for="request-reason" :error="$errors->first('requestReason')">
                                            <x-ui.textarea id="request-reason" wire:model="requestReason" rows="3" maxlength="1000"
                                                           placeholder="Why do you need this access? e.g. incident 4711, checking the orders backfill" />
                                        </x-ui.field>

                                        <div class="flex items-center gap-2">
                                            <x-ui.btn type="submit" variant="primary" wire:loading.attr="disabled" wire:target="requestAccess">Send request</x-ui.btn>
                                            <x-ui.btn type="button" wire:click="cancelAccessRequest">Cancel</x-ui.btn>
                                        </div>
                                    </form>
                                @endif
                            </div>
                        @empty
                            <x-ui.empty>
                                Nothing to request: this team has no connections yet, or you already have permanent access to all of them.
                            </x-ui.empty>
                        @endforelse

                        @if ($access['pending']->isNotEmpty() || $access['decided']->isNotEmpty())
                            <div class="border-t border-line">
                                <div class="eyebrow px-3.5 pb-1 pt-3">Your requests</div>
                                @foreach ($access['pending']->concat($access['decided']) as $grantRequest)
                                    <div class="border-b border-line px-3.5 py-2.5 last:border-b-0">
                                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                            <span class="min-w-0 truncate font-mono text-[12px] text-ink-2">{{ $grantRequest->connection->name }}</span>
                                            <x-ui.badge :tone="$grantRequest->status->tone()">{{ $grantRequest->status->label() }}</x-ui.badge>
                                            <span class="ml-auto font-mono text-[10.5px] text-mute-4">
                                                {{ $grantRequest->access_level->label() }} · {{ $grantRequest->durationLabel() }}
                                            </span>
                                        </div>
                                        <p class="mt-1.5 whitespace-pre-line break-words text-[12px] text-ink-4">{{ $grantRequest->reason }}</p>
                                        <p class="mt-1 font-mono text-[10.5px] text-mute-4">
                                            @if ($grantRequest->isPending())
                                                asked {{ $grantRequest->created_at->diffForHumans() }}
                                            @else
                                                decided {{ $grantRequest->decided_at->diffForHumans() }}
                                            @endif
                                        </p>
                                        @if ($grantRequest->decision_note)
                                            <p class="mt-1 whitespace-pre-line break-words text-[12px] text-ink-3">DBA: {{ $grantRequest->decision_note }}</p>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </x-ui.panel>
                @endif
            </div>
        </div>
    @endif
</div>

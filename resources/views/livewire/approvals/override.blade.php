<div>
    <x-ui.page-header
        title="Override approval"
        subtitle="You are not a DBA of this team, or this is your own request. Approving it is an override: it needs a written reason, which is stored in the audit log next to your identity." />

    <div class="flex flex-col gap-3.5">
        <x-ui.panel>
            <x-slot:header>
                <a href="{{ route('requests.show', $request) }}" class="font-mono text-[12px] text-accent hover:brightness-125">#{{ $request->id }}</a>
                @if ($request->title)
                    <span class="truncate text-[13px] font-medium text-ink">{{ $request->title }}</span>
                @endif
                <x-ui.badge :tone="$request->type->tone()">{{ $request->type->label() }}</x-ui.badge>
                <span class="ml-auto shrink-0 font-mono text-[11px] text-mute-3">
                    {{ $request->requester->name }} · {{ $request->connection->name }} · {{ $request->created_at->diffForHumans() }}
                </span>
            </x-slot:header>

            <div class="p-3.5">
                @can('viewRawSql', $request)
                    <x-ui.code>{{ $request->sql_prepared }}</x-ui.code>
                @else
                    <x-ui.code>{{ $request->redactedSql() ?? 'SQL hidden — it could not be redacted safely.' }}</x-ui.code>
                @endcan
            </div>

            @if ($request->note)
                <div class="border-t border-line p-3.5">
                    <div class="eyebrow mb-1.5">Requester's reason</div>
                    <p class="whitespace-pre-line text-[12.5px] text-ink-3">{{ $request->note }}</p>
                </div>
            @endif
        </x-ui.panel>

        <x-ui.panel padded>
            <form wire:submit="approve" class="flex flex-col gap-3">
                <x-ui.field label="Override reason" :error="$errors->first('reason')">
                    <x-ui.textarea wire:model="reason" rows="4" autofocus
                                   placeholder="Why does this request need to be approved outside the normal DBA review?" />
                </x-ui.field>

                <div class="flex items-center gap-2.5">
                    <x-ui.btn type="submit" variant="ok" icon="check">Approve by override &amp; execute</x-ui.btn>
                    <a href="{{ route('requests.show', $request) }}" class="font-mono text-[11.5px] text-mute-3 hover:text-ink-3">Back to the request</a>
                </div>
            </form>
        </x-ui.panel>
    </div>
</div>

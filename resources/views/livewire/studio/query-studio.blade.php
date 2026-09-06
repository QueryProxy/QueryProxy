<div>
    <div class="mb-6">
        <h1 class="text-2xl font-bold tracking-tight">Query Studio</h1>
        <p class="mt-1 text-sm text-slate-500">
            Write SQL, validate it against the guard rules, and submit it for approval.
            SELECTs are limited to {{ config('queryproxy.select_hard_limit') }} rows;
            UPDATE / DELETE require a WHERE clause; multiple statements need an explicit transaction.
        </p>
    </div>

    @if($connections->isEmpty())
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-4 text-sm text-amber-800">
            You don't have access to any connection in this team yet. Ask a DBA to grant you one.
        </div>
    @else
        <div class="space-y-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Connection</label>
                    <select wire:model="connectionId" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                        <option value="">— choose a connection —</option>
                        @foreach($connections as $connection)
                            <option value="{{ $connection->id }}">{{ $connection->name }} ({{ $connection->driver->label() }})</option>
                        @endforeach
                    </select>
                    @error('connectionId') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Title <span class="text-slate-400">(optional)</span></label>
                    <input type="text" wire:model="title" placeholder="e.g. Fix duplicated order rows"
                           class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                    @error('title') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">SQL</label>
                <div wire:ignore
                     x-data
                     x-init="window.createSqlEditor($refs.editor, $wire.sql, (value) => $wire.$set('sql', value, false))"
                     class="overflow-hidden rounded-md border border-slate-300 bg-white">
                    <div x-ref="editor"></div>
                </div>
                @error('sql') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
            </div>

            @if($violations)
                <div class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                    <p class="mb-1 font-semibold">The request cannot be submitted:</p>
                    <ul class="list-inside list-disc space-y-0.5">
                        @foreach($violations as $violation)
                            <li>{{ $violation }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if($validated && $preparedPreview !== null)
                <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                    <p class="mb-1 font-semibold">
                        Guards passed — will run as
                        <span class="rounded bg-emerald-100 px-1.5 py-0.5 text-xs font-bold uppercase">{{ $previewType }}</span>
                    </p>
                    <pre class="mt-2 overflow-x-auto rounded bg-white/60 p-2 font-mono text-xs">{{ $preparedPreview }}</pre>
                </div>
            @endif

            <div class="flex items-center gap-3">
                <button wire:click="validateSql" wire:loading.attr="disabled"
                        class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    Validate
                </button>
                <button wire:click="submit" wire:loading.attr="disabled"
                        class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                    Submit for Approval
                </button>
                <span wire:loading class="text-sm text-slate-400">Working…</span>
            </div>
        </div>
    @endif
</div>

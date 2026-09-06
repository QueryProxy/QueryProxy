<div>
    <div class="mb-6">
        <h1 class="text-2xl font-bold tracking-tight">ChatOps Settings</h1>
        <p class="mt-1 text-sm text-slate-500">
            New requests are announced in your chat channel with Approve / Reject actions.
            Incoming callbacks are verified with HMAC SHA-256 signatures.
        </p>
    </div>

    @if(session('status'))
        <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {{-- Slack --}}
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-lg font-semibold">Slack</h2>
                @if($hasSlack)
                    <button wire:click="remove('slack')" wire:confirm="Remove the Slack integration?"
                            class="text-sm font-medium text-rose-600 hover:text-rose-500">Remove</button>
                @endif
            </div>
            <form wire:submit="saveSlack" class="space-y-3">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Incoming webhook URL</label>
                    <input type="url" wire:model="slackWebhookUrl" placeholder="https://hooks.slack.com/services/…"
                           class="w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-xs">
                    @error('slackWebhookUrl') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">
                        Signing secret @if($hasSlack)<span class="text-slate-400">(blank = keep current)</span>@endif
                    </label>
                    <input type="password" wire:model="slackSigningSecret" autocomplete="new-password"
                           class="w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-xs">
                </div>
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" wire:model="slackEnabled" class="rounded border-slate-300 text-indigo-600">
                    Enabled
                </label>
                <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Save Slack</button>
            </form>
            <div class="mt-4 rounded-md bg-slate-50 p-3 text-xs leading-5 text-slate-500">
                <p class="font-semibold text-slate-600">Setup</p>
                1. Create a Slack app with an incoming webhook and interactivity enabled.<br>
                2. Point interactivity to <code class="rounded bg-slate-200 px-1">{{ route('webhooks.slack') }}</code>.<br>
                3. Paste the app's signing secret above.<br>
                4. Link Slack member IDs to users in Admin → Users.
            </div>
        </div>

        {{-- Teams --}}
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-lg font-semibold">Microsoft Teams</h2>
                @if($hasTeams)
                    <button wire:click="remove('teams')" wire:confirm="Remove the Teams integration?"
                            class="text-sm font-medium text-rose-600 hover:text-rose-500">Remove</button>
                @endif
            </div>
            <form wire:submit="saveTeams" class="space-y-3">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Incoming webhook URL</label>
                    <input type="url" wire:model="teamsWebhookUrl" placeholder="https://outlook.office.com/webhook/…"
                           class="w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-xs">
                    @error('teamsWebhookUrl') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">
                        HMAC secret @if($hasTeams)<span class="text-slate-400">(blank = keep current)</span>@endif
                    </label>
                    <input type="password" wire:model="teamsSigningSecret" autocomplete="new-password"
                           class="w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-xs">
                </div>
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" wire:model="teamsEnabled" class="rounded border-slate-300 text-indigo-600">
                    Enabled
                </label>
                <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Save Teams</button>
            </form>
            <div class="mt-4 rounded-md bg-slate-50 p-3 text-xs leading-5 text-slate-500">
                <p class="font-semibold text-slate-600">Setup</p>
                1. Add an incoming webhook to your channel for announcements.<br>
                2. For approve/reject actions, call
                <code class="rounded bg-slate-200 px-1">{{ route('webhooks.teams') }}</code>
                with an <code class="rounded bg-slate-200 px-1">Authorization: HMAC …</code> header
                (e.g. from an outgoing webhook or Power Automate flow).
            </div>
        </div>
    </div>
</div>

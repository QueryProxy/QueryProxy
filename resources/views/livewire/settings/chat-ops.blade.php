<div>
    <x-ui.page-header
        title="ChatOps Settings"
        subtitle="New requests are announced in your chat channel with Approve / Reject actions. Incoming callbacks are verified with HMAC SHA-256 signatures." />

    @if (session('status'))
        <x-ui.alert tone="ok" icon="check" class="mb-3.5">{{ session('status') }}</x-ui.alert>
    @endif

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <x-ui.panel>
            <x-slot:header>
                <x-ui.icon name="slack" :size="15" class="text-ink-4" />
                <span class="font-display text-[12.5px] font-semibold text-ink">Slack</span>
                @if ($hasSlack)
                    <x-ui.action wire:click="remove('slack')" wire:confirm="Remove the Slack integration?" tone="danger" class="ml-auto">Remove</x-ui.action>
                @endif
            </x-slot:header>

            <div class="p-3.5">
                <form wire:submit="saveSlack" class="flex flex-col gap-3">
                    <x-ui.field label="Incoming webhook URL" :error="$errors->first('slackWebhookUrl')">
                        <x-ui.input type="url" wire:model="slackWebhookUrl" class="font-mono text-[11.5px]"
                                    placeholder="https://hooks.slack.com/services/…" />
                    </x-ui.field>

                    <x-ui.field label="Signing secret" :hint="$hasSlack ? 'blank = keep current' : null">
                        <x-ui.input type="password" wire:model="slackSigningSecret" autocomplete="new-password" class="font-mono text-[11.5px]" />
                    </x-ui.field>

                    <label class="flex items-center gap-2 text-[12.5px] text-ink-4">
                        <x-ui.checkbox wire:model="slackEnabled" />
                        Enabled
                    </label>

                    <x-ui.btn type="submit" variant="primary" class="self-start">Save Slack</x-ui.btn>
                </form>

                <div class="mt-3.5 rounded-control border border-line bg-canvas p-3 text-[11.5px] leading-[1.7] text-mute">
                    <p class="eyebrow mb-1.5">Setup</p>
                    1. Create a Slack app with an incoming webhook and interactivity enabled.<br>
                    2. Point interactivity to <code class="rounded-badge bg-raised px-1 font-mono text-ink-3">{{ route('webhooks.slack') }}</code>.<br>
                    3. Paste the app's signing secret above.<br>
                    4. Link Slack member IDs to users in Admin → Users.
                </div>
            </div>
        </x-ui.panel>

        <x-ui.panel>
            <x-slot:header>
                <x-ui.icon name="chat" :size="15" class="text-ink-4" />
                <span class="font-display text-[12.5px] font-semibold text-ink">Microsoft Teams</span>
                @if ($hasTeams)
                    <x-ui.action wire:click="remove('teams')" wire:confirm="Remove the Teams integration?" tone="danger" class="ml-auto">Remove</x-ui.action>
                @endif
            </x-slot:header>

            <div class="p-3.5">
                <form wire:submit="saveTeams" class="flex flex-col gap-3">
                    <x-ui.field label="Incoming webhook URL" :error="$errors->first('teamsWebhookUrl')">
                        <x-ui.input type="url" wire:model="teamsWebhookUrl" class="font-mono text-[11.5px]"
                                    placeholder="https://outlook.office.com/webhook/…" />
                    </x-ui.field>

                    <x-ui.field label="HMAC secret" :hint="$hasTeams ? 'blank = keep current' : null">
                        <x-ui.input type="password" wire:model="teamsSigningSecret" autocomplete="new-password" class="font-mono text-[11.5px]" />
                    </x-ui.field>

                    <label class="flex items-center gap-2 text-[12.5px] text-ink-4">
                        <x-ui.checkbox wire:model="teamsEnabled" />
                        Enabled
                    </label>

                    <x-ui.btn type="submit" variant="primary" class="self-start">Save Teams</x-ui.btn>
                </form>

                <div class="mt-3.5 rounded-control border border-line bg-canvas p-3 text-[11.5px] leading-[1.7] text-mute">
                    <p class="eyebrow mb-1.5">Setup</p>
                    1. Add an incoming webhook to your channel for announcements.<br>
                    2. For approve/reject actions, call
                    <code class="rounded-badge bg-raised px-1 font-mono text-ink-3">{{ route('webhooks.teams') }}</code>
                    with an <code class="rounded-badge bg-raised px-1 font-mono text-ink-3">Authorization: HMAC …</code> header
                    (e.g. from an outgoing webhook or Power Automate flow).
                </div>
            </div>
        </x-ui.panel>
    </div>
</div>

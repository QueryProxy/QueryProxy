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
                    <x-ui.field label="Incoming webhook URL" :error="$errors->first('slackWebhookUrl')" :hint="$hasSlack ? 'blank = keep current' : null">
                        <x-ui.input type="url" wire:model="slackWebhookUrl" class="font-mono text-[11.5px]"
                                    placeholder="{{ $hasSlack ? 'configured — enter a new URL to replace' : 'https://hooks.slack.com/services/…' }}" />
                    </x-ui.field>

                    @if ($canRotateSecret)
                        @if ($hasSlack)
                            <x-ui.field label="Current signing secret" :error="$errors->first('slackCurrentSigningSecret')" hint="required to replace it">
                                <x-ui.input type="password" wire:model="slackCurrentSigningSecret" autocomplete="off" class="font-mono text-[11.5px]" />
                            </x-ui.field>
                        @endif

                        <x-ui.field :label="$hasSlack ? 'New signing secret' : 'Signing secret'" :error="$errors->first('slackSigningSecret')" :hint="$hasSlack ? 'blank = keep current' : null">
                            <x-ui.input type="password" wire:model="slackSigningSecret" autocomplete="new-password" class="font-mono text-[11.5px]" />
                        </x-ui.field>
                    @else
                        <x-ui.alert tone="neutral" icon="lock">
                            The signing secret is what proves an incoming Approve / Reject callback really came from Slack,
                            so only a system admin can set or rotate it. You can still change the webhook URL and toggle the integration.
                        </x-ui.alert>
                    @endif

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
                    <x-ui.field label="Incoming webhook URL" :error="$errors->first('teamsWebhookUrl')" :hint="$hasTeams ? 'blank = keep current' : null">
                        <x-ui.input type="url" wire:model="teamsWebhookUrl" class="font-mono text-[11.5px]"
                                    placeholder="{{ $hasTeams ? 'configured — enter a new URL to replace' : 'https://xxx.webhook.office.com/…' }}" />
                    </x-ui.field>

                    @if ($canRotateSecret)
                        @if ($hasTeams)
                            <x-ui.field label="Current HMAC secret" :error="$errors->first('teamsCurrentSigningSecret')" hint="required to replace it">
                                <x-ui.input type="password" wire:model="teamsCurrentSigningSecret" autocomplete="off" class="font-mono text-[11.5px]" />
                            </x-ui.field>
                        @endif

                        <x-ui.field :label="$hasTeams ? 'New HMAC secret' : 'HMAC secret'" :error="$errors->first('teamsSigningSecret')" :hint="$hasTeams ? 'blank = keep current' : null">
                            <x-ui.input type="password" wire:model="teamsSigningSecret" autocomplete="new-password" class="font-mono text-[11.5px]" />
                        </x-ui.field>
                    @else
                        <x-ui.alert tone="neutral" icon="lock">
                            The HMAC secret is what proves an incoming approve / reject call really came from your automation,
                            so only a system admin can set or rotate it. You can still change the webhook URL and toggle the integration.
                        </x-ui.alert>
                    @endif

                    <label class="flex items-center gap-2 text-[12.5px] text-ink-4">
                        <x-ui.checkbox wire:model="teamsEnabled" />
                        Enabled
                    </label>

                    <x-ui.btn type="submit" variant="primary" class="self-start">Save Teams</x-ui.btn>
                </form>

                <div class="mt-3.5 rounded-control border border-line bg-canvas p-3 text-[11.5px] leading-[1.7] text-mute">
                    <p class="eyebrow mb-1.5">Setup</p>
                    1. Add an incoming webhook to your channel for announcements.<br>
                    2. For approve/reject actions, have your Power Automate flow POST to
                    <code class="rounded-badge bg-raised px-1 font-mono text-ink-3">{{ route('webhooks.teams') }}</code>
                    with an <code class="rounded-badge bg-raised px-1 font-mono text-ink-3">X-QueryProxy-Timestamp</code> header (unix seconds) and
                    <code class="rounded-badge bg-raised px-1 font-mono text-ink-3">Authorization: HMAC base64(hmac_sha256("&#123;timestamp&#125;:&#123;body&#125;", secret))</code>.<br>
                    3. The body carries the approver's AAD object id as
                    <code class="rounded-badge bg-raised px-1 font-mono text-ink-3">actor_id</code>; link AAD ids to users in Admin → Users (Teams ID).<br>
                    4. It must also carry <code class="rounded-badge bg-raised px-1 font-mono text-ink-3">token</code>, copied from the
                    <code class="rounded-badge bg-raised px-1 font-mono text-ink-3">queryproxy.action_token</code> field of the card QueryProxy posted.
                    Each token decides one request once, and expires after {{ \App\Models\ChatApprovalToken::LIFETIME_HOURS }} hours.
                </div>
            </div>
        </x-ui.panel>
    </div>
</div>

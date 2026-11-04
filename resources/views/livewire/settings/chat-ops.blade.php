<div>
    <x-ui.page-header
        title="ChatOps Settings"
        subtitle="New requests are announced in your chat channel with Approve / Reject actions. Incoming callbacks are verified with HMAC SHA-256 signatures." />

    @if (session('status'))
        <x-ui.alert tone="ok" icon="check" class="mb-3.5" role="status">{{ session('status') }}</x-ui.alert>
    @endif

    @if (session('slack_install_error'))
        <x-ui.alert tone="danger" icon="shield" class="mb-3.5">{{ session('slack_install_error') }}</x-ui.alert>
    @endif

    @if ($errors->has('remove'))
        <x-ui.alert tone="danger" icon="lock" class="mb-3.5">{{ $errors->first('remove') }}</x-ui.alert>
    @endif

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <x-ui.panel>
            <x-slot:header>
                <x-ui.icon name="slack" :size="15" class="text-ink-4" />
                <span class="font-display text-[12.5px] font-semibold text-ink">Slack</span>
                @if ($hasSlack && $canRemove)
                    <x-ui.action wire:click="remove('slack')" wire:confirm="Remove the Slack integration, including the app installation and the webhook? Only an admin can set up the webhook again." tone="danger" class="ml-auto">Remove</x-ui.action>
                @endif
            </x-slot:header>

            <div class="p-3.5">
                <section aria-labelledby="slack-app-heading" class="mb-4 flex flex-col gap-3 border-b border-line pb-4">
                    <h2 id="slack-app-heading" class="eyebrow">Slack app</h2>

                    @if ($errors->has('slackApp'))
                        <x-ui.alert tone="danger" icon="shield">{{ $errors->first('slackApp') }}</x-ui.alert>
                    @endif

                    @if ($slackApp)
                        <div class="flex flex-wrap items-center gap-2">
                            <x-ui.badge tone="ok">Installed</x-ui.badge>
                            <span class="text-[12px] text-mute">Requests are posted by the bot and the message is updated as the status changes.</span>
                        </div>

                        <dl class="rounded-control border border-line bg-canvas px-3 py-1.5">
                            <x-ui.kv label="Workspace" :hint="$slackApp->slack_team_name ? $slackApp->slack_team_id : null">{{ $slackApp->slack_team_name ?? $slackApp->slack_team_id }}</x-ui.kv>
                            <x-ui.kv label="Channel">
                                @if ($slackApp->slack_channel_id)
                                    <span class="font-mono text-[11.5px]">{{ $slackApp->slack_channel_id }}</span>
                                @else
                                    <span class="text-mute-4">none chosen</span>
                                @endif
                            </x-ui.kv>
                            <x-ui.kv label="Installed">
                                {{ $slackApp->installed_at?->format('Y-m-d H:i') ?? 'unknown' }}@if ($slackApp->installer) <span class="text-mute-4">by</span> {{ $slackApp->installer->name }}@endif
                            </x-ui.kv>
                        </dl>

                        @unless ($slackApp->slack_channel_id)
                            <x-ui.alert tone="pending" icon="alert">
                                No channel was chosen, so announcements still go through the incoming webhook below
                                and direct messages to approvers cannot be sent. Add the app to Slack again and pick a channel.
                            </x-ui.alert>
                        @endunless

                        <label class="flex items-start gap-2 text-[12.5px] text-ink-4">
                            <x-ui.checkbox wire:model.live="slackDmApprovers" class="mt-0.5" :disabled="! $slackApp->slack_channel_id" />
                            <span>
                                Also send a direct message to the team's DBAs for each new request
                                <span class="block text-[11.5px] text-mute-3">
                                    The message holds a link only: no SQL and no Approve button.
                                    @unless ($slackApp->slack_channel_id)Needs a channel.@endunless
                                </span>
                            </span>
                        </label>

                        <div class="flex flex-wrap items-center gap-3">
                            @if ($slackAppAvailable)
                                <x-ui.btn :href="route('integrations.slack.install')" icon="slack">Add to Slack again</x-ui.btn>
                            @endif
                            <x-ui.action wire:click="uninstallSlack"
                                         wire:confirm="Remove the Slack app from this team? The bot token is deleted and notifications stop, unless a webhook is set up. Remove the app in the Slack workspace as well to revoke its access there."
                                         tone="danger">Remove app</x-ui.action>
                        </div>
                    @elseif ($slackAppAvailable)
                        <p class="text-[12px] leading-[1.55] text-mute">
                            Install the QueryProxy app to post requests as a bot, update the message in place as they are
                            approved, run and finish, and open requests with <code class="rounded-badge bg-raised px-1 font-mono text-ink-3">/queryproxy</code>.
                        </p>
                        <div>
                            <x-ui.btn :href="route('integrations.slack.install')" variant="primary" icon="slack">Add to Slack</x-ui.btn>
                        </div>
                    @else
                        <x-ui.alert tone="neutral" icon="lock">
                            The Slack app is not set up on this server. Set
                            <code class="font-mono">QUERYPROXY_SLACK_CLIENT_ID</code>,
                            <code class="font-mono">QUERYPROXY_SLACK_CLIENT_SECRET</code> and
                            <code class="font-mono">QUERYPROXY_SLACK_SIGNING_SECRET</code> to enable one-click install.
                            The incoming webhook below works without it.
                        </x-ui.alert>
                    @endif
                </section>

                <h2 class="eyebrow mb-2">Incoming webhook</h2>

                <form wire:submit="saveSlack" class="flex flex-col gap-3">
                    <x-ui.field label="Incoming webhook URL" :error="$errors->first('slackWebhookUrl')" :hint="$hasSlackWebhook ? 'blank = keep current' : ($slackApp ? 'optional while the app is installed' : null)">
                        <x-ui.input type="url" wire:model="slackWebhookUrl" class="font-mono text-[11.5px]"
                                    placeholder="{{ $hasSlackWebhook ? 'configured — enter a new URL to replace' : 'https://hooks.slack.com/services/…' }}" />
                    </x-ui.field>

                    @if ($canRotateSecret)
                        @if ($hasSlackSecret)
                            <x-ui.field label="Current signing secret" :error="$errors->first('slackCurrentSigningSecret')" hint="required to replace it">
                                <x-ui.input type="password" wire:model="slackCurrentSigningSecret" autocomplete="off" class="font-mono text-[11.5px]" />
                            </x-ui.field>
                        @endif

                        <x-ui.field :label="$hasSlackSecret ? 'New signing secret' : 'Signing secret'" :error="$errors->first('slackSigningSecret')" :hint="$hasSlackSecret ? 'blank = keep current' : null">
                            <x-ui.input type="password" wire:model="slackSigningSecret" autocomplete="new-password" class="font-mono text-[11.5px]" />
                        </x-ui.field>
                    @else
                        <x-ui.alert tone="neutral" icon="lock">
                            The signing secret is what proves an incoming Approve / Reject callback really came from Slack,
                            so only a system admin can set or rotate it — or remove the integration, which would need a new secret to
                            set up again. You can still change the webhook URL and untick Enabled to switch Slack off.
                        </x-ui.alert>
                    @endif

                    <label class="flex items-center gap-2 text-[12.5px] text-ink-4">
                        <x-ui.checkbox wire:model="slackEnabled" />
                        Enabled
                    </label>

                    <x-ui.btn type="submit" variant="primary" class="self-start">Save Slack</x-ui.btn>
                </form>

                <div class="mt-3.5 rounded-control border border-line bg-canvas p-3 text-[11.5px] leading-[1.7] text-mute">
                    <p class="eyebrow mb-1.5">Webhook setup</p>
                    1. Create a Slack app with an incoming webhook and interactivity enabled.<br>
                    2. Point interactivity to <code class="rounded-badge bg-raised px-1 font-mono text-ink-3">{{ route('webhooks.slack') }}</code>.<br>
                    3. Paste the app's signing secret above.<br>
                    4. Link Slack member IDs to users in Teams &amp; Users → Manage users.
                </div>
            </div>
        </x-ui.panel>

        <x-ui.panel>
            <x-slot:header>
                <x-ui.icon name="chat" :size="15" class="text-ink-4" />
                <span class="font-display text-[12.5px] font-semibold text-ink">Microsoft Teams</span>
                @if ($hasTeams && $canRemove)
                    <x-ui.action wire:click="remove('teams')" wire:confirm="Remove the Teams integration? Only an admin can configure it again." tone="danger" class="ml-auto">Remove</x-ui.action>
                @endif
            </x-slot:header>

            <div class="p-3.5">
                <form wire:submit="saveTeams" class="flex flex-col gap-3">
                    <x-ui.field label="Incoming webhook URL" :error="$errors->first('teamsWebhookUrl')" :hint="$hasTeams ? 'blank = keep current' : 'Power Automate Workflows URL; Office 365 connectors are retired'">
                        <x-ui.input type="url" wire:model="teamsWebhookUrl" class="font-mono text-[11.5px]"
                                    placeholder="{{ $hasTeams ? 'configured — enter a new URL to replace' : 'https://….environment.api.powerplatform.com/…/workflows/…' }}" />
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
                            so only a system admin can set or rotate it — or remove the integration, which would need a new secret to
                            set up again. You can still change the webhook URL and untick Enabled to switch Teams off.
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
                    <code class="rounded-badge bg-raised px-1 font-mono text-ink-3">actor_id</code>; link AAD ids to users in Teams &amp; Users → Manage users (Teams ID).<br>
                    4. It must also carry <code class="rounded-badge bg-raised px-1 font-mono text-ink-3">token</code>, copied from the
                    <code class="rounded-badge bg-raised px-1 font-mono text-ink-3">queryproxy.action_token</code> field of the card QueryProxy posted.
                    Each token decides one request once, and expires after {{ \App\Models\ChatApprovalToken::LIFETIME_HOURS }} hours.
                </div>
            </div>
        </x-ui.panel>
    </div>
</div>

<div>
    <x-ui.page-header
        title="SCIM provisioning"
        subtitle="Let your identity provider create, update and suspend QueryProxy users and sync their groups. A user removed in the identity provider is suspended here: sessions and tokens end, history stays." />

    @if (! $enabled)
        <x-ui.alert tone="neutral" icon="lock" class="mb-3.5">
            SCIM is switched off on this instance, so every SCIM request is answered with 404 and no token can be created.
            To turn it on, set <code class="font-mono text-ink-2">QUERYPROXY_SCIM_ENABLED=true</code> in the
            environment and restart the application. Tokens that already exist are listed below and can still be revoked.
        </x-ui.alert>
    @endif

    @if ($notice)
        <x-ui.alert tone="ok" icon="check" class="mb-3.5" role="status">{{ $notice }}</x-ui.alert>
    @endif

    <div class="grid grid-cols-1 gap-4">
        <x-ui.panel id="scim-endpoint" title="Endpoint" padded>
            <p class="mb-3 text-[12.5px] text-mute">
                Enter this base URL as the tenant or SCIM connector URL in your identity provider,
                and authenticate with a SCIM token as a bearer token.
            </p>

            <div x-data="{
                     copied: false,
                     failed: false,
                     async copy() {
                         let ok = false;
                         try {
                             await navigator.clipboard.writeText(this.$refs.url.value);
                             ok = true;
                         } catch (e) {
                             this.$refs.url.focus();
                             this.$refs.url.select();
                             try { ok = document.execCommand('copy'); } catch (e2) { ok = false; }
                         }
                         this.copied = ok;
                         this.failed = ! ok;
                     },
                 }">
                <div class="flex flex-wrap items-center gap-2">
                    <label for="scim-endpoint-url" class="sr-only">SCIM endpoint base URL</label>
                    <input id="scim-endpoint-url" x-ref="url" type="text" readonly value="{{ $endpoint }}"
                           autocomplete="off" spellcheck="false" x-on:focus="$el.select()"
                           class="h-[30px] min-w-0 flex-1 basis-56 rounded-control border border-line bg-canvas px-2.5 font-mono text-[12px] text-ink-2 outline-none focus:border-accent">
                    <x-ui.btn x-on:click="copy()" aria-label="Copy the SCIM endpoint URL">Copy</x-ui.btn>
                </div>
                <p class="mt-1.5 min-h-4 text-[12px]" :class="failed ? 'text-danger' : 'text-ok'" role="status"
                   x-text="copied ? 'Copied to the clipboard.' : (failed ? 'Copy failed. The URL is selected: copy it by hand.' : '')"></p>
            </div>

            <p class="mt-1 text-[12px] text-mute">
                Resources: <code class="font-mono text-ink-3">/Users</code>, <code class="font-mono text-ink-3">/Groups</code>,
                <code class="font-mono text-ink-3">/ServiceProviderConfig</code>, <code class="font-mono text-ink-3">/Schemas</code>,
                <code class="font-mono text-ink-3">/ResourceTypes</code>.
            </p>
        </x-ui.panel>

        <x-ui.panel id="scim-tokens" title="SCIM tokens" padded>
            <p class="mb-3.5 text-[12.5px] text-mute">
                A SCIM token belongs to your identity provider, not to a person, and reaches only the SCIM endpoint.
                Send it as <code class="font-mono text-ink-3">Authorization: Bearer &lt;token&gt;</code>.
                Only a hash is stored, so the value is shown once, when you create it.
            </p>

            {{-- The new token lives only here, in the browser's memory (see Scim). --}}
            <div wire:ignore
                 x-data="{
                     token: null,
                     name: '',
                     copied: false,
                     failed: false,
                     async copy() {
                         let ok = false;
                         try {
                             await navigator.clipboard.writeText(this.token);
                             ok = true;
                         } catch (e) {
                             this.$refs.value.focus();
                             this.$refs.value.select();
                             try { ok = document.execCommand('copy'); } catch (e2) { ok = false; }
                         }
                         this.copied = ok;
                         this.failed = ! ok;
                     },
                 }"
                 x-on:scim-token-created.window="token = $event.detail.token; name = $event.detail.name; copied = false; failed = false; $nextTick(() => { $refs.value.focus(); $refs.value.select() })"
                 x-show="token" x-cloak
                 class="mb-3.5">
                <div class="rounded-[7px] border border-pending-line bg-pending-bg px-3 py-2.5">
                    <p class="mb-2 text-[12px] leading-[1.5] text-ink-3">
                        Token <strong class="font-semibold text-ink" x-text="name"></strong> created.
                        Copy it now: it is shown only once and cannot be recovered.
                    </p>
                    <div class="flex flex-wrap items-center gap-2">
                        <label for="scim-token-value" class="sr-only">New SCIM token value</label>
                        <input id="scim-token-value" x-ref="value" type="text" readonly :value="token"
                               autocomplete="off" spellcheck="false" x-on:focus="$el.select()"
                               class="h-[30px] min-w-0 flex-1 basis-56 rounded-control border border-line bg-canvas px-2.5 font-mono text-[12px] text-ink-2 outline-none focus:border-accent">
                        <x-ui.btn x-on:click="copy()" variant="primary" aria-label="Copy the new SCIM token">Copy</x-ui.btn>
                        <x-ui.btn x-on:click="token = null; name = ''; copied = false; failed = false; document.getElementById('scim-token-name')?.focus()">Done</x-ui.btn>
                    </div>
                    <p class="mt-1.5 min-h-4 text-[12px]" :class="failed ? 'text-danger' : 'text-ok'" role="status"
                       x-text="copied ? 'Copied to the clipboard.' : (failed ? 'Copy failed. The value is selected: copy it by hand before you press Done.' : '')"></p>
                </div>
            </div>

            @if ($enabled)
                <form wire:submit="create" class="flex flex-col gap-3.5">
                    <x-ui.field label="Token name" for="scim-token-name" :error="$errors->first('name')">
                        <x-ui.input id="scim-token-name" type="text" wire:model="name" maxlength="100"
                                    placeholder="e.g. entra-production" autocomplete="off" />
                    </x-ui.field>

                    @if ($confirmsWithSso)
                        @if ($ssoFresh)
                            <p class="text-[12px] text-mute">You signed in through SSO a few minutes ago, so no further confirmation is needed.</p>
                        @else
                            <x-ui.alert tone="pending" icon="shield">
                                Creating a token needs a recent sign-in. Your account has no local password, so sign in
                                through SSO again (it must be within the last {{ \App\Livewire\Settings\Scim::SSO_FRESH_MINUTES }} minutes); you come back to this page afterwards.
                                <x-ui.btn :href="route('oidc.reauthenticate', ['return' => 'scim'])" variant="primary" size="sm" class="mt-2">Sign in with SSO again</x-ui.btn>
                            </x-ui.alert>
                        @endif
                    @else
                        <div class="grid gap-3.5 sm:grid-cols-2">
                            <x-ui.field label="Confirm with your password" for="scim-token-confirm-password">
                                <x-ui.input id="scim-token-confirm-password" type="password" wire:model="confirmPassword" autocomplete="current-password" />
                            </x-ui.field>
                            @if ($twoFactorEnabled)
                                <x-ui.field label="Or a code" for="scim-token-confirm-code" hint="(authenticator app)">
                                    <x-ui.input id="scim-token-confirm-code" type="text" wire:model="confirmCode" inputmode="numeric" autocomplete="one-time-code" placeholder="123 456" class="font-mono" />
                                </x-ui.field>
                            @endif
                        </div>
                    @endif

                    @if ($errors->first('sso'))
                        <p class="text-[12px] text-danger" role="alert">{{ $errors->first('sso') }}</p>
                    @endif

                    @if ($errors->first('confirmation'))
                        <p class="text-[12px] text-danger" role="alert">{{ $errors->first('confirmation') }}</p>
                    @endif

                    <x-ui.btn type="submit" variant="primary" icon="plus" class="self-start" wire:loading.attr="disabled" wire:target="create"
                              :disabled="$confirmsWithSso && ! $ssoFresh">
                        Create token
                    </x-ui.btn>
                </form>
            @endif

            <div class="@if ($enabled) mt-5 border-t border-line pt-3.5 @endif">
                <h3 class="eyebrow mb-2">Tokens</h3>

                @forelse ($tokens as $token)
                    @php($revoked = $token->isRevoked())
                    <div wire:key="scim-token-{{ $token->id }}" class="flex flex-wrap items-start gap-x-3 gap-y-1.5 border-b border-line py-2.5 last:border-b-0">
                        <div class="min-w-0 flex-1 basis-60">
                            <p class="flex flex-wrap items-center gap-2 text-[13px] font-medium text-ink-2">
                                <span class="min-w-0 break-words">{{ $token->name }}</span>
                                @if ($revoked)
                                    <x-ui.badge tone="danger">Revoked</x-ui.badge>
                                @else
                                    <x-ui.badge tone="ok">Active</x-ui.badge>
                                @endif
                            </p>
                            <p class="mt-1.5 text-[12px] text-mute">
                                Created <span title="{{ $token->created_at->copy()->utc()->format('Y-m-d H:i') }} UTC">{{ $token->created_at->format('Y-m-d') }}</span>
                                by {{ $token->creator?->name ?? 'the command line' }}
                                &middot;
                                @if ($token->last_used_at)
                                    last used <span title="{{ $token->last_used_at->copy()->utc()->format('Y-m-d H:i') }} UTC">{{ $token->last_used_at->diffForHumans() }}</span>
                                @else
                                    never used
                                @endif
                                @if ($revoked)
                                    &middot; revoked <span title="{{ $token->revoked_at->copy()->utc()->format('Y-m-d H:i') }} UTC">{{ $token->revoked_at->format('Y-m-d') }}</span>
                                @endif
                            </p>
                        </div>
                        @unless ($revoked)
                            <x-ui.btn size="sm" variant="danger" icon="trash"
                                      wire:click="revoke({{ $token->id }})"
                                      wire:confirm="Revoke the SCIM token &quot;{{ $token->name }}&quot;? The identity provider stops syncing until you give it a new token."
                                      aria-label="Revoke SCIM token {{ $token->name }}">Revoke</x-ui.btn>
                        @endunless
                    </div>
                @empty
                    <x-ui.empty>No SCIM tokens yet.</x-ui.empty>
                @endforelse
            </div>
        </x-ui.panel>
    </div>
</div>

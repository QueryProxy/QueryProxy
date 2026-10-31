<div>
    <x-ui.panel id="api-tokens" title="API tokens" padded class="mt-3.5">
        <p class="mb-3.5 text-[12.5px] text-mute">
            Personal access tokens let scripts and pipelines call the REST API as you.
            A token can never do more than you can, a scope only narrows it, and it always expires.
            Send it as <code class="font-mono text-ink-3">Authorization: Bearer &lt;token&gt;</code>.
        </p>

        {{-- The new token lives only here, in the browser's memory (see ApiTokens). --}}
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
             x-on:api-token-created.window="token = $event.detail.token; name = $event.detail.name; copied = false; failed = false; $nextTick(() => { $refs.value.focus(); $refs.value.select() })"
             x-show="token" x-cloak
             class="mb-3.5">
            <div class="rounded-[7px] border border-pending-line bg-pending-bg px-3 py-2.5">
                <p class="mb-2 text-[12px] leading-[1.5] text-ink-3">
                    Token <strong class="font-semibold text-ink" x-text="name"></strong> created.
                    Copy it now: it is shown only once and cannot be recovered.
                </p>
                <div class="flex flex-wrap items-center gap-2">
                    <label for="api-token-value" class="sr-only">New token value</label>
                    <input id="api-token-value" x-ref="value" type="text" readonly :value="token"
                           autocomplete="off" spellcheck="false" x-on:focus="$el.select()"
                           class="h-[30px] min-w-0 flex-1 basis-56 rounded-control border border-line bg-canvas px-2.5 font-mono text-[12px] text-ink-2 outline-none focus:border-accent">
                    <x-ui.btn x-on:click="copy()" variant="primary">Copy</x-ui.btn>
                    <x-ui.btn x-on:click="token = null; name = ''; copied = false; failed = false; document.getElementById('api-token-name')?.focus()">Done</x-ui.btn>
                </div>
                <p class="mt-1.5 min-h-4 text-[12px]" :class="failed ? 'text-danger' : 'text-ok'" role="status"
                   x-text="copied ? 'Copied to the clipboard.' : (failed ? 'Copy failed. The value is selected: copy it by hand before you press Done.' : '')"></p>
            </div>
        </div>

        @if ($notice)
            <x-ui.alert tone="ok" icon="check" class="mb-3.5" role="status">{{ $notice }}</x-ui.alert>
        @endif

        @if ($enrolFirst)
            <x-ui.alert tone="danger" icon="shield">
                This instance requires two-factor authentication for your role, so you cannot create an API
                token until you have turned it on. Use the "Two-factor authentication" section on this page,
                then come back here. Tokens you already have do not work until then.
            </x-ui.alert>
        @else
        <form wire:submit="create" class="flex flex-col gap-3.5">
            <div class="grid gap-3.5 sm:grid-cols-[1fr_9rem]">
                <x-ui.field label="Token name" for="api-token-name" :error="$errors->first('name')">
                    <x-ui.input id="api-token-name" type="text" wire:model="name" maxlength="100"
                                placeholder="e.g. nightly report job" autocomplete="off" />
                </x-ui.field>

                <x-ui.field label="Expires in" for="api-token-days" :hint="'(days, max '.$maxDays.')'" :error="$errors->first('days')">
                    <x-ui.input id="api-token-days" type="number" wire:model="days" min="1" max="{{ $maxDays }}"
                                inputmode="numeric" class="font-mono" />
                </x-ui.field>
            </div>

            <fieldset class="min-w-0">
                <legend class="eyebrow mb-1.5">Scopes</legend>
                <div class="flex flex-col gap-2">
                    @foreach ($allowedScopes as $scope)
                        <label class="flex items-start gap-2 text-[12.5px] text-ink-3">
                            <x-ui.checkbox wire:model="scopes" value="{{ $scope }}" class="mt-0.5" />
                            <span class="min-w-0">
                                <code class="font-mono text-[12px] text-ink-2">{{ $scope }}</code>
                                <span class="block text-[12px] text-mute">{{ $scopeLabels[$scope] ?? '' }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
                @if ($errors->first('scopes'))
                    <p class="mt-1.5 text-[12px] text-danger">{{ $errors->first('scopes') }}</p>
                @endif
            </fieldset>

            @if ($confirmsWithSso)
                @if ($ssoFresh)
                    <p class="text-[12px] text-mute">You signed in through SSO a few minutes ago, so no further confirmation is needed.</p>
                @else
                    <x-ui.alert tone="pending" icon="shield">
                        Creating a token needs a recent sign-in. Your account has no local password, so sign in
                        through SSO again (it must be within the last {{ \App\Livewire\Profile\ApiTokens::SSO_FRESH_MINUTES }} minutes); you come back here afterwards.
                        <x-ui.btn :href="route('oidc.reauthenticate')" variant="primary" size="sm" class="mt-2">Sign in with SSO again</x-ui.btn>
                    </x-ui.alert>
                @endif
                @if ($errors->first('sso'))
                    <p class="text-[12px] text-danger">{{ $errors->first('sso') }}</p>
                @endif
            @else
                <div class="grid gap-3.5 sm:grid-cols-2">
                    <x-ui.field label="Confirm with your password" for="api-token-confirm-password">
                        <x-ui.input id="api-token-confirm-password" type="password" wire:model="confirmPassword" autocomplete="current-password" />
                    </x-ui.field>
                    @if ($twoFactorEnabled)
                        <x-ui.field label="Or a code" for="api-token-confirm-code" hint="(authenticator app)">
                            <x-ui.input id="api-token-confirm-code" type="text" wire:model="confirmCode" inputmode="numeric" autocomplete="one-time-code" placeholder="123 456" class="font-mono" />
                        </x-ui.field>
                    @endif
                </div>
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

        <div class="mt-5 border-t border-line pt-3.5">
            <h3 class="eyebrow mb-2">Your tokens</h3>

            @forelse ($tokens as $token)
                @php($expired = $token['expires_at'] !== null && $token['expires_at']->isPast())
                <div wire:key="api-token-{{ $token['id'] }}" class="flex flex-wrap items-start gap-x-3 gap-y-1.5 border-b border-line py-2.5 last:border-b-0">
                    <div class="min-w-0 flex-1 basis-60">
                        <p class="flex flex-wrap items-center gap-2 text-[13px] font-medium text-ink-2">
                            <span class="min-w-0 break-words">{{ $token['name'] }}</span>
                            @if ($expired)
                                <x-ui.badge tone="danger">Expired</x-ui.badge>
                            @endif
                        </p>
                        <p class="mt-1 flex flex-wrap gap-1">
                            @foreach ($token['scopes'] as $scope)
                                <code class="rounded-badge border border-line bg-canvas px-1.5 py-px font-mono text-[11px] text-ink-4">{{ $scope }}</code>
                            @endforeach
                        </p>
                        <p class="mt-1.5 text-[12px] text-mute">
                            @if ($token['expires_at'])
                                {{ $expired ? 'Expired' : 'Expires' }}
                                <span title="{{ $token['expires_at']->copy()->utc()->format('Y-m-d H:i') }} UTC">{{ $token['expires_at']->format('Y-m-d') }}</span>
                            @endif
                            &middot;
                            @if ($token['last_used_at'])
                                last used <span title="{{ $token['last_used_at']->copy()->utc()->format('Y-m-d H:i') }} UTC">{{ $token['last_used_at']->diffForHumans() }}</span>
                            @else
                                never used
                            @endif
                        </p>
                    </div>
                    <x-ui.btn size="sm" variant="danger" icon="trash"
                              wire:click="revoke({{ $token['id'] }})"
                              wire:confirm="Revoke the token &quot;{{ $token['name'] }}&quot;? Anything using it stops working immediately."
                              aria-label="Revoke token {{ $token['name'] }}">Revoke</x-ui.btn>
                </div>
            @empty
                <x-ui.empty>You have no API tokens.</x-ui.empty>
            @endforelse
        </div>
    </x-ui.panel>
</div>

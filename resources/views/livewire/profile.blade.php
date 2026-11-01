<div class="mx-auto max-w-xl">
    <x-ui.page-header title="Profile" :subtitle="auth()->user()->email" />

    @if (session('status'))
        <x-ui.alert tone="ok" icon="check" class="mb-3.5">{{ session('status') }}</x-ui.alert>
    @endif

    <x-ui.panel title="Name" padded class="mb-3.5">
        <form wire:submit="updateName" class="flex items-end gap-2.5">
            <x-ui.field class="flex-1" :error="$errors->first('name')">
                <x-ui.input type="text" wire:model="name" />
            </x-ui.field>
            <x-ui.btn type="submit" variant="primary">Save</x-ui.btn>
        </form>
    </x-ui.panel>

    <x-ui.panel title="Change password" padded>
        @if ($hasLocalPassword)
            <form wire:submit="updatePassword" class="flex flex-col gap-3.5">
                <x-ui.field label="Current password" :error="$errors->first('currentPassword')">
                    <x-ui.input type="password" wire:model="currentPassword" autocomplete="current-password" />
                </x-ui.field>

                <x-ui.field label="New password" :error="$errors->first('password')">
                    <x-ui.input type="password" wire:model="password" autocomplete="new-password" />
                </x-ui.field>

                <x-ui.field label="New password (again)">
                    <x-ui.input type="password" wire:model="password_confirmation" autocomplete="new-password" />
                </x-ui.field>

                <x-ui.btn type="submit" variant="primary" class="self-start">Change password</x-ui.btn>
            </form>
        @else
            <p class="text-[12.5px] text-mute">Your password is managed by your identity provider.</p>
        @endif
    </x-ui.panel>

    <x-ui.panel title="Two-factor authentication" padded class="mt-3.5">
        @if (session('recovery_codes'))
            <x-ui.alert tone="pending" icon="key" class="mb-3.5">
                <p class="mb-1.5 font-medium">Recovery codes — save them now, they are shown only once:</p>
                <div class="grid grid-cols-2 gap-x-4 gap-y-1 font-mono text-[12px]">
                    @foreach (session('recovery_codes') as $code)
                        <span>{{ $code }}</span>
                    @endforeach
                </div>
            </x-ui.alert>
        @endif

        @if ($twoFactorRequired && ! $twoFactorEnabled)
            <x-ui.alert tone="danger" icon="shield" class="mb-3.5">
                This instance requires two-factor authentication for your role. Enable it below.
            </x-ui.alert>
        @endif

        @if ($twoFactorEnabled)
            <p class="mb-3.5 text-[12.5px] text-mute">
                Two-factor authentication is <span class="font-medium text-ink-3">enabled</span>.
                Logins require a code from your authenticator app.
            </p>

            <form wire:submit="disableTwoFactor" class="flex items-end gap-2.5">
                <x-ui.field label="Confirm password to disable" class="flex-1" :error="$errors->first('disablePassword')">
                    <x-ui.input type="password" wire:model="disablePassword" autocomplete="current-password" />
                </x-ui.field>
                <x-ui.btn type="submit" variant="danger">Disable 2FA</x-ui.btn>
            </form>
        @elseif ($setupSecret)
            <div class="flex flex-col gap-3.5">
                <p class="text-[12.5px] text-mute">
                    Scan the QR code with your authenticator app (or enter the secret manually), then confirm with a code.
                </p>

                <div class="flex items-start gap-4">
                    <div class="rounded-control border border-line bg-white p-2">{!! $setupQrSvg !!}</div>
                    <div class="text-[12px] text-mute">
                        <p class="eyebrow mb-1">Manual secret</p>
                        <code class="rounded-badge border border-line bg-canvas px-1.5 py-0.5 font-mono text-ink-3">{{ $setupSecret }}</code>
                    </div>
                </div>

                <form wire:submit="confirmTwoFactor" class="flex items-end gap-2.5">
                    <x-ui.field label="Code from your app" class="flex-1" :error="$errors->first('twoFactorCode')">
                        <x-ui.input type="text" wire:model="twoFactorCode" inputmode="numeric" autocomplete="one-time-code" placeholder="123 456" class="font-mono" />
                    </x-ui.field>
                    <x-ui.btn type="submit" variant="primary">Confirm</x-ui.btn>
                    <x-ui.btn type="button" wire:click="cancelTwoFactorEnrollment">Cancel</x-ui.btn>
                </form>
            </div>
        @else
            <p class="mb-3.5 text-[12.5px] text-mute">
                Add a second factor (TOTP authenticator app) to protect your account.
            </p>
            <x-ui.btn wire:click="startTwoFactorEnrollment" variant="primary">Enable 2FA</x-ui.btn>
        @endif
    </x-ui.panel>

    <x-ui.panel title="E-mail notifications" padded class="mt-3.5">
        @unless ($mailConfigured)
            <x-ui.alert tone="pending" icon="shield" class="mb-3.5">
                This instance is not set up to send e-mail (<code class="font-mono">MAIL_MAILER</code> is <code class="font-mono">log</code>, <code class="font-mono">array</code> or empty).
                Your choices are saved, but nothing is sent until an administrator configures a mail server.
            </x-ui.alert>
        @endunless

        <p class="mb-3.5 text-[12.5px] text-mute">
            Choose what you also get by e-mail. Everything is off by default. E-mails never contain query results,
            and the SQL, when shown, has its values hidden. Links open the request in QueryProxy and ask you to sign in.
        </p>

        <form wire:submit="saveNotificationPreferences" class="flex flex-col gap-2.5">
            @foreach ($mailEvents as $event)
                <label class="flex items-center gap-2 text-[13px] text-ink-3">
                    <input type="checkbox" wire:model="mailPreferences.{{ $event->value }}" id="mail-{{ $event->value }}">
                    {{ $event->label() }}
                </label>
            @endforeach

            <x-ui.btn type="submit" variant="primary" class="mt-1 self-start">Save preferences</x-ui.btn>
        </form>
    </x-ui.panel>

    <livewire:profile.api-tokens />
</div>

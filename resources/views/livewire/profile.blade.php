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
</div>

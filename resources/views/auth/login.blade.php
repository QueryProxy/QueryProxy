<x-layouts::guest title="Log in" heading="Log in" subheading="Database access control & query approval.">
    @if ($errors->has('sso'))
        <x-ui.alert tone="danger" icon="alert" class="mb-3.5">{{ $errors->first('sso') }}</x-ui.alert>
    @endif

    @if ($ssoEnabled)
        <x-ui.btn href="{{ route('oidc.redirect') }}" variant="{{ $localLogin === 'none' ? 'primary' : 'secondary' }}" size="md" class="w-full">Log in with SSO</x-ui.btn>
    @endif

    @if ($ssoEnabled && $localLogin !== 'none')
        <p class="my-3.5 text-center text-[12px] text-ink-4">or with a password{{ $localLogin === 'admins_only' ? ' (administrators)' : '' }}</p>
    @endif

    @if ($localLogin !== 'none')
        <form method="POST" action="{{ route('login') }}" class="flex flex-col gap-3.5">
            @csrf

            <x-ui.field label="Email" for="email" :error="$errors->first('email')">
                <x-ui.input id="email" name="email" type="email" required autofocus autocomplete="username"
                            value="{{ old('email') }}" />
            </x-ui.field>

            <x-ui.field label="Password" for="password" :error="$errors->first('password')">
                <x-ui.input id="password" name="password" type="password" required autocomplete="current-password" />
            </x-ui.field>

            <div class="flex items-center justify-between gap-3">
                <label class="flex items-center gap-2 text-[12.5px] text-ink-4">
                    <x-ui.checkbox name="remember" />
                    Remember me
                </label>
                <a href="{{ route('password.request') }}" class="text-[12.5px] font-medium text-accent hover:brightness-125">Forgot password?</a>
            </div>

            <x-ui.btn type="submit" variant="primary" size="md" class="mt-1 w-full">Log in</x-ui.btn>
        </form>
    @elseif (! $ssoEnabled)
        <x-ui.alert tone="danger" icon="alert">Password login is disabled and single sign-on is not configured. Ask your administrator.</x-ui.alert>
    @elseif ($errors->has('email'))
        <x-ui.alert tone="danger" icon="alert" class="mt-3.5">{{ $errors->first('email') }}</x-ui.alert>
    @endif
</x-layouts::guest>

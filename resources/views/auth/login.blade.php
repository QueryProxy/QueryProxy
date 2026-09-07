<x-layouts::guest title="Log in" heading="Log in" subheading="Database access control &amp; query approval.">
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
</x-layouts::guest>

<x-layouts::guest title="Forgot password" heading="Forgot your password?" subheading="We'll email you a reset link.">
    @if (session('status'))
        <x-ui.alert tone="ok" icon="check" class="mb-3.5">{{ session('status') }}</x-ui.alert>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-3.5">
        @csrf

        <x-ui.field label="Email" for="email" :error="$errors->first('email')">
            <x-ui.input id="email" name="email" type="email" required autofocus autocomplete="username"
                        value="{{ old('email') }}" />
        </x-ui.field>

        <x-ui.btn type="submit" variant="primary" class="w-full">Send reset link</x-ui.btn>

        <p class="text-center">
            <a href="{{ route('login') }}" class="text-[12.5px] font-medium text-accent hover:brightness-125">Back to login</a>
        </p>
    </form>
</x-layouts::guest>

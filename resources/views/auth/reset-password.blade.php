<x-layouts::guest title="Reset password" heading="Choose a new password">
    <form method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-3.5">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <x-ui.field label="Email" for="email" :error="$errors->first('email')">
            <x-ui.input id="email" name="email" type="email" required autocomplete="username"
                        value="{{ old('email', request('email')) }}" />
        </x-ui.field>

        <x-ui.field label="New password" for="password" :error="$errors->first('password')">
            <x-ui.input id="password" name="password" type="password" required autocomplete="new-password" />
        </x-ui.field>

        <x-ui.field label="New password (again)" for="password_confirmation">
            <x-ui.input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" />
        </x-ui.field>

        <x-ui.btn type="submit" variant="primary" class="w-full">Reset password</x-ui.btn>
    </form>
</x-layouts::guest>

<x-layouts::guest title="Two-factor authentication" heading="Two-factor authentication" subheading="Enter the 6-digit code from your authenticator app.">
    <form method="POST" action="{{ route('two-factor.challenge') }}" class="flex flex-col gap-3.5">
        @csrf

        <x-ui.field label="Authentication code" for="code" :error="$errors->first('code')">
            <x-ui.input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code"
                        autofocus placeholder="123 456" class="font-mono" />
        </x-ui.field>

        <x-ui.field label="…or a recovery code" for="recovery_code" hint="Each recovery code works once.">
            <x-ui.input id="recovery_code" name="recovery_code" type="text" autocomplete="off"
                        placeholder="ABCDE-FGHIJ" class="font-mono" />
        </x-ui.field>

        <x-ui.btn type="submit" variant="primary" size="md" class="mt-1 w-full">Verify</x-ui.btn>

        <a href="{{ route('login') }}" class="text-center text-[12.5px] font-medium text-accent hover:brightness-125">Back to login</a>
    </form>
</x-layouts::guest>

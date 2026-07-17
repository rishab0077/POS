<x-guest-layout>
    <x-auth-card>
        <x-slot name="logo">
            <x-application-logo class="w-20 h-20 fill-current text-gray-500" />
        </x-slot>

        <h1 class="text-xl font-semibold text-gray-900">Multi-factor authentication</h1>
        <p class="mt-3 text-sm text-gray-600">Enter the current code from your authenticator app.</p>

        <x-auth-validation-errors class="mt-4" :errors="$errors" />

        <form method="POST" action="{{ route('mfa.challenge.verify') }}" class="mt-4">
            @csrf
            <x-label for="code" :value="__('Authentication code')" />
            <x-input id="code" class="mt-1 block w-full" type="text" name="code"
                inputmode="numeric" pattern="[0-9]*" maxlength="8" autofocus autocomplete="one-time-code" />
            <x-button class="mt-4">Verify</x-button>
        </form>

        <div class="my-5 border-t"></div>
        <p class="text-sm text-gray-600">Lost access to the authenticator? Use one unused recovery code.</p>
        <form method="POST" action="{{ route('mfa.challenge.verify') }}" class="mt-3">
            @csrf
            <x-label for="recovery_code" :value="__('Recovery code')" />
            <x-input id="recovery_code" class="mt-1 block w-full" type="text" name="recovery_code"
                autocomplete="one-time-code" />
            <x-button class="mt-4">Use recovery code</x-button>
        </form>

        <form method="POST" action="{{ route('logout') }}" class="mt-5">
            @csrf
            <button class="text-sm underline text-gray-600">Log out</button>
        </form>
    </x-auth-card>
</x-guest-layout>

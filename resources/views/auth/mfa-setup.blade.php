<x-guest-layout>
    <x-auth-card>
        <x-slot name="logo">
            <x-application-logo class="w-20 h-20 fill-current text-gray-500" />
        </x-slot>

        <h1 class="text-xl font-semibold text-gray-900">Set up multi-factor authentication</h1>
        <p class="mt-3 text-sm text-gray-600">
            {{ $required ? 'MFA is required for your role.' : 'Add MFA to protect your account.' }}
            In Microsoft Authenticator, Google Authenticator, 1Password, or another TOTP app,
            add an account manually using the key below.
        </p>

        <div class="mt-4 rounded bg-gray-100 p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-600">Account</p>
            <p class="mt-1 text-sm text-gray-900">{{ config('security.mfa.issuer') }} — {{ auth()->user()->username }}</p>
            <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-gray-600">Secret key</p>
            <code class="mt-1 block break-all rounded bg-white p-3 text-sm">{{ $secret }}</code>
            <details class="mt-3 text-xs text-gray-600">
                <summary class="cursor-pointer">Show provisioning URI</summary>
                <code class="mt-2 block break-all rounded bg-white p-3">{{ $provisioningUri }}</code>
            </details>
        </div>

        <x-auth-validation-errors class="mt-4" :errors="$errors" />

        <form method="POST" action="{{ route('mfa.setup.confirm') }}" class="mt-4 space-y-4">
            @csrf
            <div>
                <x-label for="password" :value="__('Current password')" />
                <x-input id="password" class="mt-1 block w-full" type="password" name="password"
                    required autocomplete="current-password" />
            </div>
            <div>
                <x-label for="code" :value="__('Six-digit authentication code')" />
                <x-input id="code" class="mt-1 block w-full" type="text" name="code"
                    inputmode="numeric" pattern="[0-9]*" maxlength="8" required autocomplete="one-time-code" />
            </div>
            <x-button>Enable MFA</x-button>
        </form>

        @unless ($required)
            <a class="mt-4 inline-block text-sm underline text-gray-600" href="{{ route('security.index') }}">Cancel</a>
        @endunless
    </x-auth-card>
</x-guest-layout>

<x-guest-layout>
    <x-auth-card>
        <x-slot name="logo">
            <x-application-logo class="w-20 h-20 fill-current text-gray-500" />
        </x-slot>

        <h1 class="text-xl font-semibold text-gray-900">Save your recovery codes</h1>
        <p class="mt-3 text-sm text-gray-600">
            Store these codes offline in a secure place. Each code works once, and they will not be shown again.
        </p>

        <div class="mt-4 grid grid-cols-1 gap-2 rounded bg-gray-100 p-4 sm:grid-cols-2">
            @foreach ($recoveryCodes as $code)
                <code class="rounded bg-white p-2 text-center">{{ $code }}</code>
            @endforeach
        </div>

        <a href="{{ route('security.index') }}"
            class="mt-5 inline-block rounded bg-gray-800 px-4 py-2 text-sm font-semibold text-white">
            Continue to security settings
        </a>
    </x-auth-card>
</x-guest-layout>

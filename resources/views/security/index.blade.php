<x-master-layout>
    @section('title', 'Security')

    <div class="mx-auto max-w-5xl space-y-6">
        <div>
            <h1 class="text-2xl font-semibold text-gray-800">Account Security</h1>
            <p class="text-sm text-gray-600">Manage MFA, recovery codes, and active browser sessions.</p>
        </div>

        <section class="rounded bg-white p-5 shadow">
            <h2 class="text-lg font-semibold text-gray-800">Multi-factor authentication</h2>
            @if (auth()->user()->hasMfaEnabled())
                <p class="mt-2 text-sm text-green-700">MFA is enabled.</p>
                <div class="mt-4 flex flex-wrap gap-3">
                    <form method="POST" action="{{ route('security.recovery-codes') }}">
                        @csrf
                        <button class="rounded bg-gray-800 px-4 py-2 text-sm text-white">Generate new recovery codes</button>
                    </form>
                    @unless (auth()->user()->requiresMfa())
                        <form method="POST" action="{{ route('security.mfa.disable') }}"
                            onsubmit="return confirm('Disable MFA for this account?');">
                            @csrf
                            @method('DELETE')
                            <button class="rounded bg-red-700 px-4 py-2 text-sm text-white">Disable MFA</button>
                        </form>
                    @endunless
                </div>
            @else
                <p class="mt-2 text-sm text-red-700">
                    MFA is not enabled. {{ auth()->user()->requiresMfa() ? 'It is mandatory for your role.' : '' }}
                </p>
                <a href="{{ route('mfa.setup') }}"
                    class="mt-4 inline-block rounded bg-green-700 px-4 py-2 text-sm text-white">Set up MFA</a>
            @endif
        </section>

        <section class="rounded bg-white shadow">
            <div class="flex items-center justify-between border-b p-5">
                <div>
                    <h2 class="text-lg font-semibold text-gray-800">Active sessions</h2>
                    <p class="text-sm text-gray-600">Sessions appear here when production uses the database session driver.</p>
                </div>
                <form method="POST" action="{{ route('security.sessions.others.destroy') }}">
                    @csrf
                    @method('DELETE')
                    <button class="rounded bg-red-700 px-4 py-2 text-sm text-white">Log out other sessions</button>
                </form>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="p-3 text-left">Device</th>
                            <th class="p-3 text-left">IP</th>
                            <th class="p-3 text-left">Last activity</th>
                            <th class="p-3 text-left">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($sessions as $session)
                            <tr class="border-t">
                                <td class="max-w-md break-words p-3">
                                    {{ $session->user_agent ?: 'Unknown device' }}
                                    @if (hash_equals($currentSessionId, $session->id))
                                        <span class="ml-2 rounded bg-green-100 px-2 py-1 text-xs text-green-800">Current</span>
                                    @endif
                                </td>
                                <td class="p-3">{{ $session->ip_address ?: 'Unknown' }}</td>
                                <td class="p-3">{{ \Carbon\Carbon::createFromTimestamp($session->last_activity)->diffForHumans() }}</td>
                                <td class="p-3">
                                    @unless (hash_equals($currentSessionId, $session->id))
                                        <form method="POST" action="{{ route('security.sessions.destroy', $session->id) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button class="text-red-700 underline">Revoke</button>
                                        </form>
                                    @endunless
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="p-4 text-gray-500">No database-backed sessions are available.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-master-layout>

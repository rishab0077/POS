<x-master-layout>
    @section('title', 'Audit Events')

    @php
        $json = function ($value) {
            if ($value === null || $value === []) {
                return '-';
            }

            return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        };
        $badge = function ($severity) {
            return match ($severity) {
                'warning' => 'bg-yellow-100 text-yellow-900 border-yellow-200',
                'error', 'critical' => 'bg-red-100 text-red-800 border-red-200',
                default => 'bg-green-100 text-green-800 border-green-200',
            };
        };
    @endphp

    <div class="space-y-6">
        <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">Audit Events</h1>
                <p class="text-sm text-gray-600">Append-only security, configuration, billing, printing, and inventory activity.</p>
            </div>
            <a href="{{ route('admin.audit-events.export', request()->query()) }}"
                class="inline-flex items-center justify-center px-4 py-2 text-sm font-semibold text-white bg-gray-800 rounded hover:bg-gray-900">
                Export CSV
            </a>
        </div>

        <form method="GET" action="{{ route('admin.audit-events.index') }}" class="p-4 bg-white border border-gray-200 rounded">
            <div class="grid gap-3 md:grid-cols-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 uppercase">From</label>
                    <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="w-full mt-1 border-gray-300 rounded">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 uppercase">To</label>
                    <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="w-full mt-1 border-gray-300 rounded">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 uppercase">Category</label>
                    <select name="category" class="w-full mt-1 border-gray-300 rounded">
                        <option value="">Any</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category }}" @selected(($filters['category'] ?? '') === $category)>{{ $category }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 uppercase">Event Type</label>
                    <select name="event_type" class="w-full mt-1 border-gray-300 rounded">
                        <option value="">Any</option>
                        @foreach ($eventTypes as $eventType)
                            <option value="{{ $eventType }}" @selected(($filters['event_type'] ?? '') === $eventType)>{{ $eventType }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 uppercase">Severity</label>
                    <select name="severity" class="w-full mt-1 border-gray-300 rounded">
                        <option value="">Any</option>
                        @foreach ($severities as $severity)
                            <option value="{{ $severity }}" @selected(($filters['severity'] ?? '') === $severity)>{{ ucfirst($severity) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 uppercase">User</label>
                    <select name="user_id" class="w-full mt-1 border-gray-300 rounded">
                        <option value="">Any</option>
                        @foreach ($users as $user)
                            <option value="{{ $user->id }}" @selected((string) ($filters['user_id'] ?? '') === (string) $user->id)>
                                {{ $user->name }} ({{ $user->username }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 uppercase">Subject Type</label>
                    <select name="subject_type" class="w-full mt-1 border-gray-300 rounded">
                        <option value="">Any</option>
                        @foreach ($subjectTypes as $subjectType)
                            <option value="{{ $subjectType }}" @selected(($filters['subject_type'] ?? '') === $subjectType)>{{ class_basename($subjectType) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 uppercase">Subject ID</label>
                    <input type="number" name="subject_id" value="{{ $filters['subject_id'] ?? '' }}" class="w-full mt-1 border-gray-300 rounded">
                </div>
                <div class="md:col-span-3">
                    <label class="block text-xs font-semibold text-gray-600 uppercase">Search</label>
                    <input type="search" name="search" value="{{ $filters['search'] ?? '' }}" class="w-full mt-1 border-gray-300 rounded" placeholder="Event, route, IP, user, subject, metadata">
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" class="px-4 py-2 text-sm font-semibold text-white bg-indigo-600 rounded hover:bg-indigo-700">Filter</button>
                    <a href="{{ route('admin.audit-events.index') }}" class="px-4 py-2 text-sm font-semibold text-gray-700 bg-gray-100 rounded hover:bg-gray-200">Clear</a>
                </div>
            </div>
        </form>

        <div class="overflow-x-auto bg-white border border-gray-200 rounded">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-xs font-semibold tracking-wider text-left text-gray-600 uppercase">Timestamp</th>
                        <th class="px-4 py-3 text-xs font-semibold tracking-wider text-left text-gray-600 uppercase">Category</th>
                        <th class="px-4 py-3 text-xs font-semibold tracking-wider text-left text-gray-600 uppercase">Event</th>
                        <th class="px-4 py-3 text-xs font-semibold tracking-wider text-left text-gray-600 uppercase">Severity</th>
                        <th class="px-4 py-3 text-xs font-semibold tracking-wider text-left text-gray-600 uppercase">User</th>
                        <th class="px-4 py-3 text-xs font-semibold tracking-wider text-left text-gray-600 uppercase">Subject</th>
                        <th class="px-4 py-3 text-xs font-semibold tracking-wider text-left text-gray-600 uppercase">Route</th>
                        <th class="px-4 py-3 text-xs font-semibold tracking-wider text-left text-gray-600 uppercase">IP</th>
                        <th class="px-4 py-3 text-xs font-semibold tracking-wider text-left text-gray-600 uppercase">Summary</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($events as $event)
                        <tr class="align-top">
                            <td class="px-4 py-3 text-sm text-gray-800 whitespace-nowrap">{{ optional($event->created_at)->format('Y-m-d H:i:s') }}</td>
                            <td class="px-4 py-3 text-sm text-gray-800">{{ $event->category }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-gray-800">{{ $event->event_type }}</td>
                            <td class="px-4 py-3 text-sm">
                                <span class="inline-flex px-2 py-1 text-xs font-semibold border rounded {{ $badge($event->severity) }}">
                                    {{ ucfirst($event->severity) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-800">
                                {{ $event->user?->name ?: ($event->user_id ? 'User #' . $event->user_id : 'System') }}
                                @if ($event->user_role)
                                    <span class="block text-xs text-gray-500">{{ $event->user_role }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-800">
                                {{ $event->subject_type ? class_basename($event->subject_type) : '-' }}
                                @if ($event->subject_id)
                                    <span class="block font-mono text-xs text-gray-500">#{{ $event->subject_id }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-gray-700">{{ $event->route_name ?: '-' }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-gray-700">{{ $event->ip_address ?: '-' }}</td>
                            <td class="px-4 py-3 text-sm text-gray-800">
                                {{ data_get($event->metadata, 'summary') ?: $event->event_type }}
                                <details class="mt-2">
                                    <summary class="text-xs font-semibold text-indigo-700 cursor-pointer">Details</summary>
                                    <div class="grid gap-2 mt-2 lg:grid-cols-3">
                                        <div>
                                            <p class="mb-1 text-xs font-semibold text-gray-600 uppercase">Before</p>
                                            <pre class="p-2 overflow-auto text-xs bg-gray-100 rounded max-h-48">{{ $json($event->before_values) }}</pre>
                                        </div>
                                        <div>
                                            <p class="mb-1 text-xs font-semibold text-gray-600 uppercase">After</p>
                                            <pre class="p-2 overflow-auto text-xs bg-gray-100 rounded max-h-48">{{ $json($event->after_values) }}</pre>
                                        </div>
                                        <div>
                                            <p class="mb-1 text-xs font-semibold text-gray-600 uppercase">Metadata</p>
                                            <pre class="p-2 overflow-auto text-xs bg-gray-100 rounded max-h-48">{{ $json($event->metadata) }}</pre>
                                        </div>
                                    </div>
                                </details>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-8 text-sm text-center text-gray-500">No audit events found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $events->links() }}
    </div>
</x-master-layout>

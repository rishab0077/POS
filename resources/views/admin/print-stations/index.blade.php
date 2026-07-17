<x-master-layout>
    @section('title', 'Print Stations')

    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold text-gray-800">Print Stations</h1>
            <p class="text-sm text-gray-600">Monitor station health, queue routing, print attempts, and Windows printer mappings.</p>
        </div>

        @if (session('print_station_token'))
            <div class="p-4 bg-yellow-100 border border-yellow-300 rounded">
                <p class="font-semibold text-yellow-900">Copy this station token now. It will not be shown again.</p>
                <code class="block mt-2 p-3 bg-white rounded break-all">{{ session('print_station_token') }}</code>
            </div>
        @endif

        <section class="grid grid-cols-2 md:grid-cols-5 gap-3">
            @foreach ([
                ['Pending', $queueCounts['pending'], 'bg-yellow-50 border-yellow-200 text-yellow-900'],
                ['Processing', $queueCounts['processing'], 'bg-blue-50 border-blue-200 text-blue-900'],
                ['Failed', $queueCounts['failed'], 'bg-red-50 border-red-200 text-red-900'],
                ['Printed Today', $queueCounts['printed_today'], 'bg-green-50 border-green-200 text-green-900'],
                ['Exhausted', $queueCounts['exhausted'], 'bg-gray-100 border-gray-300 text-gray-900'],
            ] as [$label, $count, $classes])
                <div class="rounded border p-4 {{ $classes }}">
                    <div class="text-xs uppercase tracking-wide">{{ $label }}</div>
                    <div class="text-2xl font-semibold">{{ $count }}</div>
                </div>
            @endforeach
        </section>

        <section class="grid grid-cols-1 lg:grid-cols-4 gap-4">
            @foreach ([
                ['Jobs by printer key', $jobsByPrinterKey],
                ['Jobs by copy type', $jobsByCopyType],
                ['Jobs by station', $jobsByStation],
            ] as [$heading, $groups])
                <div class="bg-white rounded shadow p-4">
                    <h2 class="font-semibold text-gray-800 mb-3">{{ $heading }}</h2>
                    <dl class="space-y-2 text-sm">
                        @forelse ($groups as $label => $count)
                            <div class="flex justify-between gap-3">
                                <dt class="text-gray-600">{{ ucfirst(str_replace('_', ' ', $label ?: 'Unassigned')) }}</dt>
                                <dd class="font-semibold">{{ $count }}</dd>
                            </div>
                        @empty
                            <div class="text-gray-500">No jobs yet.</div>
                        @endforelse
                    </dl>
                </div>
            @endforeach

            <div class="bg-white rounded shadow p-4">
                <h2 class="font-semibold text-gray-800 mb-3">Last error summary</h2>
                <div class="space-y-3 text-sm">
                    @forelse ($lastErrors as $errorJob)
                        <div>
                            <div class="font-medium text-red-800">Job #{{ $errorJob->id }} · {{ $errorJob->printer_key }}</div>
                            <div class="text-gray-700 break-words">{{ $errorJob->last_error }}</div>
                            <div class="text-xs text-gray-500">{{ $errorJob->updated_at->format('Y-m-d H:i:s') }}</div>
                        </div>
                    @empty
                        <div class="text-gray-500">No recorded job errors.</div>
                    @endforelse
                </div>
            </div>
        </section>

        <section class="bg-white rounded shadow p-4">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Register Reception Print Station</h2>
            <form method="POST" action="{{ route('admin.print-stations.store') }}" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-3">
                @csrf
                <input class="border rounded p-2" name="name" placeholder="Reception PC" required>
                <input class="border rounded p-2" name="kitchen_printer" placeholder="KITCHEN_KOT">
                <input class="border rounded p-2" name="bar_printer" placeholder="BAR_BOT">
                <input class="border rounded p-2" name="counter_printer" placeholder="COUNTER_BILL">
                <input class="border rounded p-2" name="biller_printer" placeholder="Optional biller printer">
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="enabled" value="1" checked>
                    Enabled
                </label>
                <button class="bg-green-700 text-white rounded px-4 py-2 md:col-span-2 lg:col-span-1">Create Station</button>
            </form>
        </section>

        <section class="bg-white rounded shadow overflow-x-auto">
            <div class="p-4 border-b">
                <h2 class="text-lg font-semibold text-gray-800">Stations</h2>
                <p class="text-xs text-gray-500">Warning includes a current station error or a failed assigned job in the last 24 hours.</p>
            </div>
            <table class="min-w-full text-sm">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="p-3 text-left">Name / status</th>
                        <th class="p-3 text-left">Heartbeat / agent</th>
                        <th class="p-3 text-left">Mappings</th>
                        <th class="p-3 text-left">Last error</th>
                        <th class="p-3 text-left">Configuration</th>
                        <th class="p-3 text-left">Token</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($stations as $station)
                        @php
                            $status = $station->operationalStatus($station->recent_failed_jobs_count > 0);
                            $statusClass = match ($status) {
                                'Online / Healthy' => 'bg-green-100 text-green-800',
                                'Online / Warning' => 'bg-yellow-100 text-yellow-900',
                                'Offline' => 'bg-red-100 text-red-800',
                                'Misconfigured' => 'bg-orange-100 text-orange-800',
                                default => 'bg-gray-200 text-gray-700',
                            };
                        @endphp
                        <tr class="border-t align-top">
                            <td class="p-3">
                                <div class="font-semibold">{{ $station->name }}</div>
                                <span class="inline-block mt-1 px-2 py-1 rounded text-xs {{ $statusClass }}">{{ $status }}</span>
                            </td>
                            <td class="p-3 whitespace-nowrap">
                                @if ($station->last_seen_at)
                                    <div>{{ $station->last_seen_at->format('Y-m-d H:i:s') }}</div>
                                    <div class="text-xs text-gray-500">{{ $station->last_seen_at->diffForHumans() }}</div>
                                @else
                                    <div class="text-gray-500">Never</div>
                                @endif
                                <div class="mt-2">Version: {{ $station->version ?: '-' }}</div>
                                <div>IP: {{ $station->last_ip ?: '-' }}</div>
                            </td>
                            <td class="p-3 min-w-56">
                                @forelse ($station->usablePrinterMap() as $key => $printerName)
                                    <div><span class="font-medium">{{ $key }}:</span> {{ $printerName }}</div>
                                @empty
                                    <span class="text-orange-700">No usable mappings</span>
                                @endforelse
                            </td>
                            <td class="p-3 max-w-xs break-words text-red-700">{{ $station->last_error ?: '-' }}</td>
                            <td class="p-3">
                                <form method="POST" action="{{ route('admin.print-stations.update', $station) }}" class="grid grid-cols-1 gap-2 min-w-64">
                                    @csrf
                                    @method('PUT')
                                    <input class="border rounded p-2" name="name" value="{{ $station->name }}" required>
                                    <input class="border rounded p-2" name="kitchen_printer" value="{{ $station->printer_map['kitchen'] ?? '' }}" placeholder="KITCHEN_KOT">
                                    <input class="border rounded p-2" name="bar_printer" value="{{ $station->printer_map['bar'] ?? '' }}" placeholder="BAR_BOT">
                                    <input class="border rounded p-2" name="counter_printer" value="{{ $station->printer_map['counter'] ?? '' }}" placeholder="COUNTER_BILL">
                                    <input class="border rounded p-2" name="biller_printer" value="{{ $station->printer_map['biller'] ?? '' }}" placeholder="Optional biller printer">
                                    <label class="flex items-center gap-2">
                                        <input type="checkbox" name="enabled" value="1" @checked($station->enabled)>
                                        Enabled
                                    </label>
                                    <button class="bg-blue-700 text-white rounded px-3 py-2">Save</button>
                                </form>
                            </td>
                            <td class="p-3">
                                <form method="POST" action="{{ route('admin.print-stations.regenerate-token', $station) }}">
                                    @csrf
                                    <button class="bg-gray-800 text-white rounded px-3 py-2">Regenerate Token</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="p-3 text-gray-500" colspan="6">No print stations registered.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section class="bg-white rounded shadow p-4">
            <div class="mb-4">
                <h2 class="text-lg font-semibold text-gray-800">Print job filters</h2>
            </div>
            <form method="GET" action="{{ route('admin.print-stations.index') }}" class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-3 text-sm">
                <select class="border rounded p-2" name="status">
                    <option value="">All statuses</option>
                    @foreach (['pending', 'processing', 'failed', 'printed'] as $value)
                        <option value="{{ $value }}" @selected(request('status') === $value)>{{ ucfirst($value) }}</option>
                    @endforeach
                </select>
                <select class="border rounded p-2" name="printer_key">
                    <option value="">All printer keys</option>
                    @foreach (['counter', 'kitchen', 'bar', 'biller'] as $value)
                        <option value="{{ $value }}" @selected(request('printer_key') === $value)>{{ ucfirst($value) }}</option>
                    @endforeach
                </select>
                <select class="border rounded p-2" name="copy_type">
                    <option value="">All copy types</option>
                    @foreach (['customer', 'restaurant', 'duplicate', 'summary'] as $value)
                        <option value="{{ $value }}" @selected(request('copy_type') === $value)>{{ ucfirst($value) }}</option>
                    @endforeach
                </select>
                <select class="border rounded p-2" name="station">
                    <option value="">All stations</option>
                    <option value="unassigned" @selected(request('station') === 'unassigned')>Unassigned</option>
                    @foreach ($stations as $station)
                        <option value="{{ $station->id }}" @selected((string) request('station') === (string) $station->id)>{{ $station->name }}</option>
                    @endforeach
                </select>
                <select class="border rounded p-2" name="type">
                    <option value="">All types</option>
                    @foreach (['bill', 'kot', 'bot', 'summary', 'duplicate'] as $value)
                        <option value="{{ $value }}" @selected(request('type') === $value)>{{ strtoupper($value) }}</option>
                    @endforeach
                </select>
                <input class="border rounded p-2" name="search" value="{{ request('search') }}" placeholder="Invoice / KOT / order / job">
                <label class="text-xs text-gray-600">
                    From
                    <input class="block w-full border rounded p-2 mt-1" type="date" name="date_from" value="{{ request('date_from') }}">
                </label>
                <label class="text-xs text-gray-600">
                    To
                    <input class="block w-full border rounded p-2 mt-1" type="date" name="date_to" value="{{ request('date_to') }}">
                </label>
                <label class="flex items-center gap-2">
                    <input type="checkbox" name="failed_only" value="1" @checked(request()->boolean('failed_only'))>
                    Failed only
                </label>
                <label class="flex items-center gap-2">
                    <input type="checkbox" name="unclaimable_only" value="1" @checked(request()->boolean('unclaimable_only'))>
                    Unclaimable only
                </label>
                <button class="bg-blue-700 text-white rounded px-4 py-2">Apply filters</button>
                <a class="border rounded px-4 py-2 text-center text-gray-700" href="{{ route('admin.print-stations.index') }}">Clear</a>
            </form>
        </section>

        <section class="bg-white rounded shadow overflow-x-auto">
            <div class="p-4 border-b flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-gray-800">Print Jobs</h2>
                    <p class="text-xs text-gray-500">{{ $jobs->total() }} matching jobs</p>
                </div>
            </div>
            <table class="min-w-full text-xs">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="p-3 text-left">Job / source</th>
                        <th class="p-3 text-left">Routing</th>
                        <th class="p-3 text-left">Status / attempts</th>
                        <th class="p-3 text-left">People</th>
                        <th class="p-3 text-left">Timestamps</th>
                        <th class="p-3 text-left">Station / error</th>
                        <th class="p-3 text-left">Actions / history</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($jobs as $job)
                        @php
                            $unclaimable = $job->isUnclaimable($mappedPrinterKeys);
                            $statusClass = match ($job->status) {
                                'failed' => 'bg-red-100 text-red-800',
                                'printed' => 'bg-green-100 text-green-800',
                                'processing' => 'bg-blue-100 text-blue-800',
                                default => 'bg-yellow-100 text-yellow-800',
                            };
                        @endphp
                        <tr class="border-t align-top">
                            <td class="p-3 min-w-36">
                                <div class="font-semibold">#{{ $job->id }}</div>
                                <div>{{ strtoupper(str_replace('_', ' ', $job->type)) }}</div>
                                <div class="text-gray-600">{{ $job->sourceIdentifier() }}</div>
                            </td>
                            <td class="p-3 min-w-32">
                                <div>Key: <span class="font-medium">{{ $job->printer_key }}</span></div>
                                <div>Copy: <span class="font-medium">{{ ucfirst($job->displayCopyType()) }}</span></div>
                                @if ($unclaimable)
                                    <span class="inline-block mt-2 px-2 py-1 rounded bg-red-700 text-white font-semibold">UNCLAIMABLE</span>
                                    <div class="mt-1 text-red-700">Add this printer key mapping to an enabled print station.</div>
                                @endif
                            </td>
                            <td class="p-3 whitespace-nowrap">
                                <span class="px-2 py-1 rounded {{ $statusClass }}">{{ ucfirst($job->status) }}</span>
                                @if ($job->isExhausted())
                                    <span class="ml-1 px-2 py-1 rounded bg-gray-800 text-white">Exhausted</span>
                                @endif
                                <div class="mt-2">{{ $job->attempts }} / {{ $job->max_attempts ?? 3 }} attempts</div>
                            </td>
                            <td class="p-3 min-w-32">
                                <div>Requested: {{ $job->requestedByName() }}</div>
                                <div>Retried: {{ $job->retriedBy?->name ?? '-' }}</div>
                            </td>
                            <td class="p-3 whitespace-nowrap text-gray-700">
                                <div>Created: {{ $job->created_at?->format('Y-m-d H:i:s') ?? '-' }}</div>
                                <div>Attempted: {{ $job->last_attempted_at?->format('Y-m-d H:i:s') ?? '-' }}</div>
                                <div>Printed: {{ $job->printed_at?->format('Y-m-d H:i:s') ?? '-' }}</div>
                                <div>Failed: {{ $job->failed_at?->format('Y-m-d H:i:s') ?? '-' }}</div>
                                <div>Locked until: {{ $job->locked_until?->format('Y-m-d H:i:s') ?? '-' }}</div>
                            </td>
                            <td class="p-3 min-w-48">
                                <div class="font-medium">{{ $job->printStation?->name ?? 'Unassigned' }}</div>
                                <div class="mt-1 break-words text-red-700">{{ $job->last_error ?: '-' }}</div>
                            </td>
                            <td class="p-3 min-w-56">
                                @if (auth()->user()?->canAdministerApplication() && $job->isRetryEligible())
                                    <form method="POST" action="{{ route('admin.print-jobs.retry', $job) }}">
                                        @csrf
                                        <button class="bg-green-700 text-white rounded px-3 py-2">Retry</button>
                                    </form>
                                    <div class="mt-1 text-gray-500">Step-up confirmation is required.</div>
                                @else
                                    <button class="bg-gray-200 text-gray-500 rounded px-3 py-2 cursor-not-allowed" disabled>Retry</button>
                                    <div class="mt-1 text-gray-500">
                                        {{ $job->status !== 'failed' ? 'Only failed jobs are eligible.' : 'You do not have permission.' }}
                                    </div>
                                @endif

                                <details class="mt-3">
                                    <summary class="cursor-pointer font-medium text-blue-700">
                                        Attempt history ({{ $job->printAttempts->count() }})
                                    </summary>
                                    <div class="mt-2 space-y-2">
                                        @forelse ($job->printAttempts as $attempt)
                                            <div class="border rounded p-2 bg-gray-50">
                                                <div class="font-semibold">Attempt {{ $attempt->attempt_number }} · {{ ucfirst($attempt->status) }}</div>
                                                <div>Station: {{ $attempt->printStation?->name ?? '-' }}</div>
                                                <div>Printer: {{ $attempt->printer_name ?: '-' }}</div>
                                                <div>Claimed: {{ $attempt->claimed_at?->format('Y-m-d H:i:s') ?? '-' }}</div>
                                                <div>Completed: {{ $attempt->completed_at?->format('Y-m-d H:i:s') ?? '-' }}</div>
                                                <div class="break-words text-red-700">Error: {{ $attempt->error ?: '-' }}</div>
                                            </div>
                                        @empty
                                            <div class="text-gray-500">No attempt records. This may be a legacy job.</div>
                                        @endforelse
                                    </div>
                                </details>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="p-3 text-gray-500" colspan="7">No print jobs match these filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <div class="p-4 border-t">
                {{ $jobs->links() }}
            </div>
        </section>
    </div>
</x-master-layout>

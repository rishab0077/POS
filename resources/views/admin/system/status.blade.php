<x-master-layout>
    @section('title', 'System Status')

    @php
        $badge = function ($level) {
            return match ($level) {
                true, 'ok' => 'bg-green-100 text-green-800 border-green-200',
                'warning' => 'bg-yellow-100 text-yellow-900 border-yellow-200',
                false, 'critical', 'failed' => 'bg-red-100 text-red-800 border-red-200',
                default => 'bg-gray-100 text-gray-800 border-gray-200',
            };
        };
        $formatBytes = function ($bytes) {
            if ($bytes === null) {
                return 'Unknown';
            }
            $units = ['B', 'KB', 'MB', 'GB', 'TB'];
            $value = (float) $bytes;
            $index = 0;
            while ($value >= 1024 && $index < count($units) - 1) {
                $value /= 1024;
                $index++;
            }

            return number_format($value, $index === 0 ? 0 : 1) . ' ' . $units[$index];
        };
    @endphp

    <div class="space-y-6">
        <div class="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">System Status</h1>
                <p class="text-sm text-gray-600">Operational health for backups, queue, printing, storage, and realtime configuration.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.audit-events.index', ['severity' => 'warning', 'date_from' => now()->subDay()->toDateString()]) }}"
                    class="inline-flex items-center px-3 py-1 text-xs font-semibold text-yellow-900 border border-yellow-200 rounded bg-yellow-50">
                    Recent audit warnings
                </a>
                <a href="{{ route('admin.audit-events.index', ['severity' => 'error', 'date_from' => now()->subDay()->toDateString()]) }}"
                    class="inline-flex items-center px-3 py-1 text-xs font-semibold text-red-800 border border-red-200 rounded bg-red-50">
                    Recent audit errors
                </a>
                <span class="inline-flex items-center px-3 py-1 text-xs font-semibold border rounded {{ $badge(data_get($status, 'database.ok')) }}">
                    Database {{ data_get($status, 'database.ok') ? 'OK' : 'Failed' }}
                </span>
            </div>
        </div>

        @if ($alerts !== [])
            <div class="p-4 bg-yellow-50 border border-yellow-200 rounded">
                <h2 class="mb-2 text-sm font-semibold text-yellow-900">Active Alerts</h2>
                <ul class="space-y-1 text-sm text-yellow-900">
                    @foreach ($alerts as $alert)
                        <li>{{ $alert['message'] }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="grid gap-4 lg:grid-cols-3">
            <section class="p-4 bg-white border border-gray-200 rounded">
                <h2 class="mb-3 text-base font-semibold text-gray-900">Application</h2>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-4"><dt class="text-gray-500">Environment</dt><dd class="font-medium text-gray-900">{{ data_get($status, 'app.environment') }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-gray-500">Commit</dt><dd class="font-mono text-xs text-gray-900 break-all">{{ data_get($status, 'app.commit') }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-gray-500">Laravel</dt><dd>{{ data_get($status, 'app.laravel_version') }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-gray-500">PHP</dt><dd>{{ data_get($status, 'app.php_version') }}</dd></div>
                </dl>
            </section>

            <section class="p-4 bg-white border border-gray-200 rounded">
                <h2 class="mb-3 text-base font-semibold text-gray-900">Runtime</h2>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-4"><dt class="text-gray-500">Session</dt><dd>{{ data_get($status, 'configuration.session_driver') }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-gray-500">Queue</dt><dd>{{ data_get($status, 'configuration.queue_driver') }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-gray-500">Broadcast</dt><dd>{{ data_get($status, 'configuration.broadcast_driver') }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-gray-500">Soketi</dt><dd>{{ data_get($status, 'soketi.configured') ? 'Configured' : 'Missing config' }}</dd></div>
                </dl>
            </section>

            <section class="p-4 bg-white border border-gray-200 rounded">
                <h2 class="mb-3 text-base font-semibold text-gray-900">Receipt Copies</h2>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-4"><dt class="text-gray-500">Customer</dt><dd>{{ data_get($status, 'configuration.receipt.customer_copy_enabled') ? 'Enabled' : 'Disabled' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-gray-500">Restaurant</dt><dd>{{ data_get($status, 'configuration.receipt.restaurant_copy_enabled') ? 'Enabled' : 'Disabled' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-gray-500">Default</dt><dd>{{ data_get($status, 'configuration.receipt.default_copies') }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-gray-500">Width</dt><dd>{{ data_get($status, 'configuration.receipt.width') }}</dd></div>
                </dl>
            </section>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="p-4 bg-white border border-gray-200 rounded">
                <h2 class="mb-3 text-base font-semibold text-gray-900">Database and Queue</h2>
                <dl class="grid grid-cols-2 gap-3 text-sm">
                    <div><dt class="text-gray-500">Database</dt><dd class="font-medium">{{ data_get($status, 'database.ok') ? 'Connected' : 'Failed' }}</dd></div>
                    <div><dt class="text-gray-500">Migrations Pending</dt><dd class="font-medium">{{ data_get($status, 'migrations.pending', 'Unknown') }}</dd></div>
                    <div><dt class="text-gray-500">Pending Jobs</dt><dd class="font-medium">{{ data_get($status, 'queue.pending_jobs_count', 'Unknown') }}</dd></div>
                    <div><dt class="text-gray-500">Failed Jobs</dt><dd class="font-medium">{{ data_get($status, 'queue.failed_jobs_count', 'Unknown') }}</dd></div>
                </dl>
            </section>

            <section class="p-4 bg-white border border-gray-200 rounded">
                <h2 class="mb-3 text-base font-semibold text-gray-900">Backups and Storage</h2>
                <dl class="grid grid-cols-2 gap-3 text-sm">
                    <div><dt class="text-gray-500">Latest Backup</dt><dd class="font-medium break-all">{{ data_get($status, 'backup.latest.file') ?: 'None found' }}</dd></div>
                    <div><dt class="text-gray-500">Backup Size</dt><dd class="font-medium">{{ $formatBytes(data_get($status, 'backup.latest.size_bytes')) }}</dd></div>
                    <div><dt class="text-gray-500">Storage Writable</dt><dd class="font-medium">{{ data_get($status, 'storage.writable') ? 'Yes' : 'No' }}</dd></div>
                    <div><dt class="text-gray-500">Disk Usage</dt><dd class="font-medium">{{ data_get($status, 'disk.used_percent') ?? 'Unknown' }}%</dd></div>
                </dl>
            </section>
        </div>

        <section class="p-4 bg-white border border-gray-200 rounded">
            <h2 class="mb-3 text-base font-semibold text-gray-900">Printing</h2>
            <div class="grid gap-3 text-sm md:grid-cols-4">
                <div><dt class="text-gray-500">Stations</dt><dd class="text-lg font-semibold">{{ data_get($status, 'printing.stations_total') }}</dd></div>
                <div><dt class="text-gray-500">Online</dt><dd class="text-lg font-semibold">{{ data_get($status, 'printing.stations_online') }}</dd></div>
                <div><dt class="text-gray-500">Offline</dt><dd class="text-lg font-semibold">{{ data_get($status, 'printing.offline_stations') }}</dd></div>
                <div><dt class="text-gray-500">Station Errors</dt><dd class="text-lg font-semibold">{{ data_get($status, 'printing.stations_with_errors') }}</dd></div>
                <div><dt class="text-gray-500">Pending Jobs</dt><dd class="text-lg font-semibold">{{ data_get($status, 'printing.pending_jobs') }}</dd></div>
                <div><dt class="text-gray-500">Failed Jobs</dt><dd class="text-lg font-semibold">{{ data_get($status, 'printing.failed_jobs') }}</dd></div>
                <div><dt class="text-gray-500">Exhausted</dt><dd class="text-lg font-semibold">{{ data_get($status, 'printing.exhausted_jobs') }}</dd></div>
                <div><dt class="text-gray-500">Unclaimable</dt><dd class="text-lg font-semibold">{{ data_get($status, 'printing.unclaimable_jobs') }}</dd></div>
            </div>
        </section>
    </div>
</x-master-layout>

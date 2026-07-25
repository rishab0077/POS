<?php

namespace App\Services\Operations;

use App\Models\CbmsSubmission;
use App\Models\FiscalCreditNote;
use App\Models\PrintJob;
use App\Models\PrintStation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SystemStatusService
{
    public function __construct(private readonly BackupFileService $backups)
    {
    }

    public function status(): array
    {
        return [
            'app' => $this->appStatus(),
            'database' => $this->databaseStatus(),
            'migrations' => $this->migrationStatus(),
            'queue' => $this->queueStatus(),
            'scheduler' => $this->schedulerStatus(),
            'clock' => $this->clockStatus(),
            'cbms' => $this->cbmsStatus(),
            'storage' => $this->storageStatus(),
            'backup' => $this->backupStatus(),
            'printing' => $this->printingStatus(),
            'soketi' => $this->soketiStatus(),
            'disk' => $this->diskStatus(),
            'configuration' => $this->configurationStatus(),
        ];
    }

    public function alertFindings(?array $status = null): array
    {
        $status ??= $this->status();
        $findings = [];

        if (!data_get($status, 'scheduler.ok')) {
            $findings[] = [
                'key' => 'scheduler_stale',
                'severity' => 'critical',
                'message' => 'Scheduler heartbeat is missing or stale.',
            ];
        }

        if (!data_get($status, 'clock.ok')) {
            $findings[] = [
                'key' => 'clock_skew',
                'severity' => 'critical',
                'message' => 'Application and database clocks are not synchronized.',
            ];
        }

        if (data_get($status, 'cbms.enabled')) {
            $failed = (int) data_get($status, 'cbms.failed', 0);
            if ($failed > 0) {
                $findings[] = [
                    'key' => 'cbms_failed',
                    'severity' => 'critical',
                    'message' => "CBMS has {$failed} failed submission(s).",
                ];
            }

            $oldest = data_get($status, 'cbms.oldest_outstanding_age_minutes');
            $maxAge = (int) config('operations.monitoring.cbms_pending_max_minutes', 15);
            if ($oldest !== null && $oldest > $maxAge) {
                $findings[] = [
                    'key' => 'cbms_pending',
                    'severity' => 'warning',
                    'message' => "Oldest unresolved CBMS submission is {$oldest} minutes old.",
                ];
            }
        }

        $failedJobs = (int) data_get($status, 'queue.failed_jobs_count', 0);
        $failedJobThreshold = (int) config('operations.monitoring.failed_job_threshold', 1);
        if ($failedJobs > $failedJobThreshold) {
            $findings[] = [
                'key' => 'failed_jobs',
                'severity' => 'critical',
                'message' => "Failed jobs are above threshold ({$failedJobs} > {$failedJobThreshold}).",
            ];
        }

        $backupAgeMinutes = data_get($status, 'backup.latest.age_minutes');
        $backupMaxAgeMinutes = (int) config('operations.monitoring.backup_max_age_hours', 30) * 60;
        if ($backupAgeMinutes === null || $backupAgeMinutes > $backupMaxAgeMinutes) {
            $findings[] = [
                'key' => 'latest_backup',
                'severity' => 'warning',
                'message' => $backupAgeMinutes === null
                    ? 'No backup file was found.'
                    : "Latest backup is older than configured threshold ({$backupAgeMinutes} minutes).",
            ];
        }

        $diskUsed = data_get($status, 'disk.used_percent');
        $diskCritical = (int) config('operations.monitoring.disk_critical_percent', 90);
        $diskWarning = (int) config('operations.monitoring.disk_warning_percent', 80);
        if ($diskUsed !== null && $diskUsed >= $diskCritical) {
            $findings[] = [
                'key' => 'disk_critical',
                'severity' => 'critical',
                'message' => "Storage disk usage is critical ({$diskUsed}%).",
            ];
        } elseif ($diskUsed !== null && $diskUsed >= $diskWarning) {
            $findings[] = [
                'key' => 'disk_warning',
                'severity' => 'warning',
                'message' => "Storage disk usage is above warning threshold ({$diskUsed}%).",
            ];
        }

        foreach ([
            'offline_stations' => 'Offline print stations detected',
            'exhausted_jobs' => 'Exhausted print jobs detected',
            'unclaimable_jobs' => 'Unclaimable print jobs detected',
        ] as $key => $label) {
            $count = (int) data_get($status, "printing.{$key}", 0);
            if ($count > 0) {
                $findings[] = [
                    'key' => $key,
                    'severity' => 'warning',
                    'message' => "{$label}: {$count}.",
                ];
            }
        }

        return $findings;
    }

    public function printingStatus(): array
    {
        if (!$this->hasTable('print_stations') || !$this->hasTable('print_jobs')) {
            return [
                'available' => false,
                'stations_total' => 0,
                'stations_enabled' => 0,
                'stations_online' => 0,
                'offline_stations' => 0,
                'stations_with_errors' => 0,
                'jobs_by_status' => [],
                'pending_jobs' => 0,
                'failed_jobs' => 0,
                'exhausted_jobs' => 0,
                'unclaimable_jobs' => 0,
            ];
        }

        $offlineCutoff = now()->subMinutes((int) config('operations.monitoring.print_station_offline_minutes', 5));
        $stations = PrintStation::query()->get();
        $enabledStations = $stations->where('enabled', true);
        $mappedPrinterKeys = $enabledStations
            ->flatMap(fn (PrintStation $station) => array_keys($station->usablePrinterMap()))
            ->unique()
            ->values()
            ->all();

        $jobsByStatus = PrintJob::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();

        $unclaimable = PrintJob::query()
            ->whereNotNull('printer_key')
            ->when($mappedPrinterKeys !== [], fn ($query) => $query->whereNotIn('printer_key', $mappedPrinterKeys))
            ->where(function ($query) {
                $query
                    ->where('status', PrintJob::STATUS_PENDING)
                    ->orWhere(function ($query) {
                        $query
                            ->where('status', PrintJob::STATUS_PROCESSING)
                            ->whereNotNull('locked_until')
                            ->where('locked_until', '<=', now());
                    });
            })
            ->count();

        return [
            'available' => true,
            'stations_total' => $stations->count(),
            'stations_enabled' => $enabledStations->count(),
            'stations_online' => $enabledStations
                ->filter(fn (PrintStation $station) => $station->last_seen_at && $station->last_seen_at->gt($offlineCutoff))
                ->count(),
            'offline_stations' => $enabledStations
                ->filter(fn (PrintStation $station) => !$station->last_seen_at || $station->last_seen_at->lte($offlineCutoff))
                ->count(),
            'stations_with_errors' => $enabledStations->filter(fn (PrintStation $station) => filled($station->last_error))->count(),
            'jobs_by_status' => $jobsByStatus,
            'pending_jobs' => (int) ($jobsByStatus[PrintJob::STATUS_PENDING] ?? 0),
            'failed_jobs' => (int) ($jobsByStatus[PrintJob::STATUS_FAILED] ?? 0),
            'exhausted_jobs' => PrintJob::query()
                ->where('status', PrintJob::STATUS_FAILED)
                ->whereRaw('attempts >= COALESCE(max_attempts, 3)')
                ->count(),
            'unclaimable_jobs' => $unclaimable,
        ];
    }

    private function appStatus(): array
    {
        return [
            'environment' => app()->environment(),
            'debug' => (bool) config('app.debug'),
            'commit' => $this->backups->deploymentSha(),
            'laravel_version' => app()->version(),
            'php_version' => PHP_VERSION,
        ];
    }

    private function databaseStatus(): array
    {
        try {
            DB::connection()->getPdo();

            return [
                'ok' => true,
                'connection' => config('database.default'),
                'database' => DB::connection()->getDatabaseName(),
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'connection' => config('database.default'),
                'database' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function migrationStatus(): array
    {
        try {
            if (!$this->hasTable('migrations')) {
                return ['ok' => false, 'ran' => 0, 'pending' => null, 'message' => 'migrations table missing'];
            }

            $migrator = app('migrator');
            $files = $migrator->getMigrationFiles(database_path('migrations'));
            $ran = $migrator->getRepository()->getRan();
            $pending = array_values(array_diff(array_keys($files), $ran));

            return [
                'ok' => $pending === [],
                'ran' => count($ran),
                'pending' => count($pending),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'ran' => null, 'pending' => null, 'error' => $e->getMessage()];
        }
    }

    private function queueStatus(): array
    {
        try {
            return [
                'connection' => config('queue.default'),
                'pending_jobs_count' => $this->hasTable('jobs') ? DB::table('jobs')->count() : null,
                'failed_jobs_count' => $this->hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null,
            ];
        } catch (Throwable $e) {
            return [
                'connection' => config('queue.default'),
                'pending_jobs_count' => null,
                'failed_jobs_count' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function schedulerStatus(): array
    {
        $lastSeen = Cache::get('operations.scheduler_last_seen');

        try {
            $lastSeen = $lastSeen ? Carbon::parse($lastSeen) : null;
        } catch (Throwable) {
            $lastSeen = null;
        }

        $age = $lastSeen ? (int) $lastSeen->diffInMinutes(now()) : null;
        $maxAge = (int) config('operations.monitoring.scheduler_max_age_minutes', 3);

        return [
            'ok' => $age !== null && $age <= $maxAge,
            'last_seen_at' => $lastSeen?->toIso8601String(),
            'age_minutes' => $age,
        ];
    }

    public function clockStatus(): array
    {
        try {
            $databaseTime = (int) DB::selectOne('SELECT UNIX_TIMESTAMP() AS unix_time')->unix_time;
            $skew = abs(time() - $databaseTime);

            return [
                'ok' => $skew <= (int) config('operations.monitoring.clock_max_skew_seconds', 5),
                'skew_seconds' => $skew,
                'timezone' => config('app.timezone'),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'skew_seconds' => null, 'timezone' => config('app.timezone'), 'error' => $e->getMessage()];
        }
    }

    public function cbmsStatus(): array
    {
        if (!$this->hasTable('cbms_submissions') || !$this->hasTable('fiscal_credit_notes')) {
            return [
                'available' => false,
                'enabled' => (bool) config('services.cbms.enabled'),
                'acceptance_mode' => (bool) config('services.cbms.acceptance_mode'),
                'configured' => false,
                'invoices' => [],
                'credit_notes' => [],
                'outstanding' => 0,
                'failed' => 0,
                'oldest_outstanding_age_minutes' => null,
            ];
        }

        $counts = fn (string $model) => $model::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();
        $invoices = $counts(CbmsSubmission::class);
        $creditNotes = $counts(FiscalCreditNote::class);
        $invoiceOldest = CbmsSubmission::where('status', '!=', 'submitted')->min('created_at');
        $creditNoteOldest = FiscalCreditNote::where('status', '!=', 'submitted')->min('created_at');
        $oldest = collect([$invoiceOldest, $creditNoteOldest])->filter()->min();

        return [
            'available' => true,
            'enabled' => (bool) config('services.cbms.enabled'),
            'acceptance_mode' => (bool) config('services.cbms.acceptance_mode'),
            'configured' => filled(config('services.cbms.username')) && filled(config('services.cbms.password')),
            'invoices' => $invoices,
            'credit_notes' => $creditNotes,
            'outstanding' => collect($invoices)->except('submitted')->sum() + collect($creditNotes)->except('submitted')->sum(),
            'failed' => (int) ($invoices['failed'] ?? 0) + (int) ($creditNotes['failed'] ?? 0),
            'oldest_outstanding_age_minutes' => $oldest ? (int) Carbon::parse($oldest)->diffInMinutes(now()) : null,
        ];
    }

    private function storageStatus(): array
    {
        $path = storage_path();
        $probe = storage_path('framework/operations-write-check.tmp');

        try {
            file_put_contents($probe, 'ok');
            @unlink($probe);
            $writable = true;
        } catch (Throwable) {
            $writable = false;
        }

        return [
            'path' => $path,
            'writable' => $writable,
        ];
    }

    private function backupStatus(): array
    {
        $latest = $this->backups->latestBackup();

        return [
            'enabled' => (bool) config('operations.backup.enabled'),
            'directory' => $this->backups->directory(),
            'latest' => $latest ? [
                'file' => $latest['file'],
                'size_bytes' => $latest['size_bytes'],
                'modified_at' => $latest['modified_at']->toIso8601String(),
                'age_minutes' => $latest['age_minutes'],
            ] : null,
        ];
    }

    private function soketiStatus(): array
    {
        return [
            'driver' => config('broadcasting.default'),
            'configured' => filled(config('broadcasting.connections.pusher.key'))
                && filled(config('broadcasting.connections.pusher.options.host')),
            'host' => config('broadcasting.connections.pusher.options.host'),
            'port' => config('broadcasting.connections.pusher.options.port'),
            'scheme' => config('broadcasting.connections.pusher.options.scheme'),
        ];
    }

    private function diskStatus(): array
    {
        $path = storage_path();
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        if (!$total || $free === false) {
            return [
                'path' => $path,
                'available' => false,
                'used_percent' => null,
                'free_bytes' => null,
                'total_bytes' => null,
                'level' => 'unknown',
            ];
        }

        $usedPercent = (int) round((($total - $free) / $total) * 100);
        $critical = (int) config('operations.monitoring.disk_critical_percent', 90);
        $warning = (int) config('operations.monitoring.disk_warning_percent', 80);

        return [
            'path' => $path,
            'available' => true,
            'used_percent' => $usedPercent,
            'free_bytes' => $free,
            'total_bytes' => $total,
            'level' => $usedPercent >= $critical ? 'critical' : ($usedPercent >= $warning ? 'warning' : 'ok'),
        ];
    }

    private function configurationStatus(): array
    {
        return [
            'session_driver' => config('session.driver'),
            'queue_driver' => config('queue.default'),
            'broadcast_driver' => config('broadcasting.default'),
            'receipt' => [
                'customer_copy_enabled' => (bool) config('pos.printing.receipt.customer_copy_enabled'),
                'restaurant_copy_enabled' => (bool) config('pos.printing.receipt.restaurant_copy_enabled'),
                'default_copies' => config('pos.printing.receipt.default_copies'),
                'width' => config('pos.printing.receipt.width'),
            ],
        ];
    }

    private function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }
}

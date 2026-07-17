<?php

namespace App\Console\Commands;

use App\Services\Operations\SystemStatusService;
use Illuminate\Console\Command;

class OperationsStatusCommand extends Command
{
    protected $signature = 'operations:status {--json : Output machine-readable JSON}';

    protected $description = 'Show operational health and system status.';

    public function handle(SystemStatusService $statusService): int
    {
        $status = $statusService->status();

        if ($this->option('json')) {
            $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info(config('app.name', 'Restaurant POS') . ' System Status');
        $this->line('Environment: ' . data_get($status, 'app.environment'));
        $this->line('Commit: ' . data_get($status, 'app.commit'));
        $this->line('Database: ' . (data_get($status, 'database.ok') ? 'ok' : 'failed'));
        $this->line('Migrations pending: ' . data_get($status, 'migrations.pending', 'unknown'));
        $this->line('Queue pending jobs: ' . data_get($status, 'queue.pending_jobs_count', 'unknown'));
        $this->line('Failed jobs: ' . data_get($status, 'queue.failed_jobs_count', 'unknown'));
        $this->line('Storage writable: ' . (data_get($status, 'storage.writable') ? 'yes' : 'no'));
        $this->line('Latest backup: ' . (data_get($status, 'backup.latest.file') ?: 'none'));
        $this->line('Print stations offline: ' . data_get($status, 'printing.offline_stations', 0));
        $this->line('Exhausted print jobs: ' . data_get($status, 'printing.exhausted_jobs', 0));
        $this->line('Unclaimable print jobs: ' . data_get($status, 'printing.unclaimable_jobs', 0));
        $this->line('Soketi configured: ' . (data_get($status, 'soketi.configured') ? 'yes' : 'no'));
        $this->line('Disk usage: ' . (data_get($status, 'disk.used_percent') ?? 'unknown') . '%');

        return self::SUCCESS;
    }
}

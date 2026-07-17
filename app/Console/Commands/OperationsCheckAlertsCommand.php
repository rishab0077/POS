<?php

namespace App\Console\Commands;

use App\Services\Operations\SystemStatusService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class OperationsCheckAlertsCommand extends Command
{
    protected $signature = 'operations:check-alerts {--json : Output alert findings as JSON}';

    protected $description = 'Check operational thresholds and send or log alerts.';

    public function handle(SystemStatusService $statusService): int
    {
        $status = $statusService->status();
        $findings = $statusService->alertFindings($status);

        if ($this->option('json')) {
            $this->line(json_encode(['findings' => $findings], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        if ($findings === []) {
            $this->info('No operations alerts triggered.');

            return self::SUCCESS;
        }

        $newFindings = collect($findings)
            ->filter(fn (array $finding) => $this->claimCooldown($finding))
            ->values()
            ->all();

        foreach ($findings as $finding) {
            Log::warning('Operations alert triggered.', $finding);
            $this->warn($finding['message']);
        }

        if ($newFindings !== []) {
            $this->sendEmail($newFindings, $status);
        }

        return self::SUCCESS;
    }

    private function claimCooldown(array $finding): bool
    {
        $minutes = max(1, (int) config('operations.monitoring.alert_cooldown_minutes', 60));
        $key = 'operations_alert_' . sha1($finding['key'] . '|' . $finding['message']);

        return Cache::add($key, true, now()->addMinutes($minutes));
    }

    private function sendEmail(array $findings, array $status): void
    {
        $email = config('operations.monitoring.alert_email');

        if (blank($email)) {
            Log::warning('Operations alert email is not configured; alerts were logged only.');

            return;
        }

        $appName = config('app.name', 'Restaurant POS');
        $body = $appName . " operations alerts:\n\n"
            . collect($findings)->map(fn (array $finding) => strtoupper($finding['severity']) . ': ' . $finding['message'])->implode("\n")
            . "\n\nEnvironment: " . data_get($status, 'app.environment')
            . "\nCommit: " . data_get($status, 'app.commit');

        try {
            Mail::raw($body, function ($message) use ($email, $appName): void {
                $message->to($email)->subject($appName . ' operations alert');
            });
        } catch (Throwable $e) {
            Log::error('Operations alert email failed.', ['error' => $e->getMessage()]);
            $this->warn('Alert email failed; warnings were logged.');
        }
    }
}

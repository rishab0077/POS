<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Cache;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->call(fn () => Cache::forever('operations.scheduler_last_seen', now()->toIso8601String()))
            ->name('operations:scheduler-heartbeat')
            ->everyMinute();

        if (config('operations.backup.enabled')) {
            $schedule->command('backup:database')
                ->dailyAt('02:00')
                ->withoutOverlapping();
        }

        $schedule->command('backup:prune')
            ->dailyAt('03:00')
            ->withoutOverlapping();

        $schedule->command('operations:check-alerts')
            ->everyFifteenMinutes()
            ->withoutOverlapping();

        if (config('services.cbms.enabled') && !config('services.cbms.acceptance_mode')) {
            $schedule->command('cbms:dispatch')
                ->everyMinute()
                ->withoutOverlapping();
        }
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}

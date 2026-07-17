<?php

namespace App\Console\Commands;

use App\Services\Operations\BackupFileService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class BackupPruneCommand extends Command
{
    protected $signature = 'backup:prune {--dry-run : Show files that would be deleted without deleting them}';

    protected $description = 'Prune old backup files according to configured retention settings.';

    public function handle(BackupFileService $backups): int
    {
        $directory = $backups->directory();

        if (!is_dir($directory)) {
            $this->line("Backup directory does not exist: {$directory}");

            return self::SUCCESS;
        }

        $root = realpath($directory);
        if ($root === false) {
            $this->error('Unable to resolve backup directory.');

            return self::FAILURE;
        }

        $files = collect(File::files($directory))
            ->filter(fn ($file) => preg_match('/\.sql(\.gz)?$/', $file->getFilename()) === 1)
            ->sortByDesc(fn ($file) => $file->getMTime())
            ->values();

        $keep = $this->keptPaths($files);
        $delete = $files->reject(fn ($file) => in_array($file->getPathname(), $keep, true))->values();

        if ($delete->isEmpty()) {
            $this->line('No backup files need pruning.');

            return self::SUCCESS;
        }

        foreach ($delete as $file) {
            $path = $file->getPathname();
            $resolved = realpath($path);

            if ($resolved === false || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
                $this->error("Refusing to delete outside backup directory: {$path}");

                return self::FAILURE;
            }

            if ($this->option('dry-run')) {
                $this->line("Would delete {$path}");
                Log::info('Backup prune dry-run would delete file.', ['path' => $path]);

                continue;
            }

            File::delete($path);
            $this->line("Deleted {$path}");
            Log::info('Backup prune deleted file.', ['path' => $path]);
        }

        return self::SUCCESS;
    }

    private function keptPaths($files): array
    {
        $daily = max(0, (int) config('operations.backup.retention.daily', 7));
        $weekly = max(0, (int) config('operations.backup.retention.weekly', 4));
        $monthly = max(0, (int) config('operations.backup.retention.monthly', 6));

        $keep = $files->take($daily)->map(fn ($file) => $file->getPathname())->all();

        $weeks = [];
        $months = [];

        foreach ($files as $file) {
            $date = Carbon::createFromTimestamp($file->getMTime());
            $weekKey = $date->isoFormat('GGGG-[W]WW');
            $monthKey = $date->format('Y-m');

            if (count($weeks) < $weekly && !isset($weeks[$weekKey])) {
                $weeks[$weekKey] = true;
                $keep[] = $file->getPathname();
            }

            if (count($months) < $monthly && !isset($months[$monthKey])) {
                $months[$monthKey] = true;
                $keep[] = $file->getPathname();
            }
        }

        return array_values(array_unique($keep));
    }
}

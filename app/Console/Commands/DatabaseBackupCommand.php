<?php

namespace App\Console\Commands;

use App\Services\Operations\BackupFileService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

class DatabaseBackupCommand extends Command
{
    protected $signature = 'backup:database {--dry-run : Show the backup target without creating a dump}';

    protected $description = 'Create a compressed MySQL database backup.';

    public function handle(BackupFileService $backups): int
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}");

        if (($database['driver'] ?? null) !== 'mysql') {
            $this->error('backup:database currently supports MySQL connections only.');

            return self::FAILURE;
        }

        $directory = $backups->ensureDirectoryExists();
        $path = $directory . DIRECTORY_SEPARATOR . $backups->makeFilename();

        if ($this->option('dry-run')) {
            $this->line('Dry run: database backup would be written to:');
            $this->line($path);

            return self::SUCCESS;
        }

        $command = [
            'mysqldump',
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            '--host=' . ($database['host'] ?? '127.0.0.1'),
            '--port=' . ($database['port'] ?? 3306),
            '--user=' . ($database['username'] ?? ''),
            (string) ($database['database'] ?? ''),
        ];

        $process = new Process($command, base_path(), [
            'MYSQL_PWD' => (string) ($database['password'] ?? ''),
        ], null, 600);

        $stderr = '';
        $gz = gzopen($path, 'wb9');

        if ($gz === false) {
            $this->error('Unable to open backup file for writing.');

            return self::FAILURE;
        }

        try {
            $exitCode = $process->run(function (string $type, string $buffer) use ($gz, &$stderr): void {
                if ($type === Process::OUT) {
                    gzwrite($gz, $buffer);

                    return;
                }

                $stderr .= $buffer;
            });
        } catch (Throwable $e) {
            gzclose($gz);
            @unlink($path);
            Log::error('Database backup failed.', ['error' => $e->getMessage()]);
            $this->error('Database backup failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        gzclose($gz);

        if ($exitCode !== 0) {
            @unlink($path);
            Log::error('Database backup failed.', [
                'exit_code' => $exitCode,
                'stderr' => trim($stderr),
            ]);
            $this->error('Database backup failed. Confirm mysqldump is installed in the app container.');

            return self::FAILURE;
        }

        clearstatcache(true, $path);

        if (!is_file($path) || filesize($path) <= 0) {
            @unlink($path);
            Log::error('Database backup did not create a non-empty file.', ['path' => $path]);
            $this->error('Database backup did not create a non-empty file.');

            return self::FAILURE;
        }

        Log::info('Database backup completed.', ['path' => $path, 'size_bytes' => filesize($path)]);
        $this->info('Database backup completed.');
        $this->line($path);

        return self::SUCCESS;
    }
}

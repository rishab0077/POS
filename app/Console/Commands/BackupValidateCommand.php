<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Throwable;

class BackupValidateCommand extends Command
{
    protected $signature = 'backup:validate
        {file : Backup file path to validate}
        {--restore : Restore into a temporary validation database and run basic table checks}
        {--database= : Validation database name. Defaults to the current database plus _restore_validation}
        {--dangerously-allow-production : Allow restore into the configured production database}';

    protected $description = 'Validate a backup file and optionally restore it into a validation database.';

    public function handle(): int
    {
        $file = (string) $this->argument('file');

        if (!is_file($file)) {
            $this->error("Backup file not found: {$file}");

            return self::FAILURE;
        }

        if (filesize($file) <= 0) {
            $this->error('Backup file is empty.');

            return self::FAILURE;
        }

        if (str_ends_with($file, '.gz') && !$this->validateGzip($file)) {
            return self::FAILURE;
        }

        $this->info('Backup file integrity checks passed.');

        if (!$this->option('restore')) {
            $this->line('Restore validation skipped. Pass --restore to validate against a temporary database.');

            return self::SUCCESS;
        }

        return $this->restoreForValidation($file);
    }

    private function validateGzip(string $file): bool
    {
        $handle = @gzopen($file, 'rb');

        if ($handle === false) {
            $this->error('Unable to open gzip backup.');

            return false;
        }

        while (!gzeof($handle)) {
            $chunk = gzread($handle, 1024 * 1024);
            if ($chunk === false) {
                gzclose($handle);
                $this->error('Gzip integrity check failed.');

                return false;
            }
        }

        if (!gzclose($handle)) {
            $this->error('Gzip integrity check failed.');

            return false;
        }

        $this->line('Gzip integrity check passed.');

        return true;
    }

    private function restoreForValidation(string $file): int
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}");

        if (($database['driver'] ?? null) !== 'mysql') {
            $this->error('Restore validation currently supports MySQL connections only.');

            return self::FAILURE;
        }

        $productionDatabase = (string) ($database['database'] ?? '');
        $validationDatabase = (string) ($this->option('database') ?: $productionDatabase . '_restore_validation');

        if ($validationDatabase === $productionDatabase && !$this->option('dangerously-allow-production')) {
            $this->error('Refusing to restore into the configured production database without --dangerously-allow-production.');

            return self::FAILURE;
        }

        $this->warn("Restoring backup into validation database: {$validationDatabase}");

        $mysqlBase = [
            'mysql',
            '--host=' . ($database['host'] ?? '127.0.0.1'),
            '--port=' . ($database['port'] ?? 3306),
            '--user=' . ($database['username'] ?? ''),
        ];
        $env = ['MYSQL_PWD' => (string) ($database['password'] ?? '')];

        $create = new Process(array_merge($mysqlBase, [
            '--execute=CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $validationDatabase) . '`',
        ]), base_path(), $env, null, 120);

        if ($create->run() !== 0) {
            $this->error('Unable to create validation database.');

            return self::FAILURE;
        }

        $sql = $this->sqlContents($file);
        if ($sql === null) {
            return self::FAILURE;
        }

        $restore = new Process(array_merge($mysqlBase, [$validationDatabase]), base_path(), $env, $sql, 600);

        if ($restore->run() !== 0) {
            $this->error('Restore into validation database failed.');

            return self::FAILURE;
        }

        $checks = $this->tableCounts($connection, $database, $validationDatabase);
        $this->table(['Table', 'Rows'], collect($checks)->map(fn ($count, $table) => [$table, $count])->all());
        $this->info('Restore validation completed.');

        return self::SUCCESS;
    }

    private function sqlContents(string $file): ?string
    {
        try {
            if (!str_ends_with($file, '.gz')) {
                return file_get_contents($file) ?: '';
            }

            $contents = gzdecode((string) file_get_contents($file));

            if ($contents === false) {
                $this->error('Unable to decompress gzip backup for restore validation.');

                return null;
            }

            return $contents;
        } catch (Throwable $e) {
            $this->error('Unable to read backup: ' . $e->getMessage());

            return null;
        }
    }

    private function tableCounts(string $connection, array $database, string $validationDatabase): array
    {
        $validationConnection = 'restore_validation';

        Config::set("database.connections.{$validationConnection}", array_merge($database, [
            'database' => $validationDatabase,
        ]));
        DB::purge($validationConnection);

        $counts = [];
        foreach (['migrations', 'users', 'print_jobs'] as $table) {
            $counts[$table] = Schema::connection($validationConnection)->hasTable($table)
                ? DB::connection($validationConnection)->table($table)->count()
                : 'missing';
        }

        DB::disconnect($validationConnection);

        return $counts;
    }
}

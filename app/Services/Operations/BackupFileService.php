<?php

namespace App\Services\Operations;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class BackupFileService
{
    public function directory(): string
    {
        return (string) config('operations.backup.storage_path', storage_path('backups'));
    }

    public function ensureDirectoryExists(): string
    {
        $directory = $this->directory();

        File::ensureDirectoryExists($directory, 0750);

        return $directory;
    }

    public function makeFilename(?string $sha = null, ?Carbon $now = null): string
    {
        $now ??= now();
        $sha = Str::of($sha ?: $this->deploymentSha())
            ->replaceMatches('/[^A-Za-z0-9._-]/', '-')
            ->limit(16, '')
            ->value();

        return sprintf('restaurant_pos_%s_%s.sql.gz', $now->format('Ymd_His'), $sha ?: 'unknown');
    }

    public function latestBackup(): ?array
    {
        $directory = $this->directory();

        if (!is_dir($directory)) {
            return null;
        }

        $files = collect(File::files($directory))
            ->filter(fn ($file) => preg_match('/\.sql(\.gz)?$/', $file->getFilename()) === 1)
            ->sortByDesc(fn ($file) => $file->getMTime())
            ->values();

        if ($files->isEmpty()) {
            return null;
        }

        $file = $files->first();

        return [
            'path' => $file->getPathname(),
            'file' => $file->getFilename(),
            'size_bytes' => $file->getSize(),
            'modified_at' => Carbon::createFromTimestamp($file->getMTime()),
            'age_minutes' => (int) Carbon::createFromTimestamp($file->getMTime())->diffInMinutes(now()),
        ];
    }

    public function deploymentSha(): string
    {
        foreach (['DEPLOY_SHA', 'GIT_SHA', 'APP_IMAGE_TAG', 'APP_VERSION'] as $key) {
            $value = env($key);

            if (filled($value) && $value !== 'latest') {
                return (string) $value;
            }
        }

        $head = base_path('.git/HEAD');

        if (is_file($head)) {
            $contents = trim((string) file_get_contents($head));

            if (str_starts_with($contents, 'ref: ')) {
                $refPath = base_path('.git/' . substr($contents, 5));

                if (is_file($refPath)) {
                    return trim((string) file_get_contents($refPath));
                }
            }

            if ($contents !== '') {
                return $contents;
            }
        }

        return 'unknown';
    }
}

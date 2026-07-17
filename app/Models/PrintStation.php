<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PrintStation extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'token_hash',
        'enabled',
        'printer_map',
        'last_seen_at',
        'version',
        'last_ip',
        'last_error',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'printer_map' => 'array',
        'last_seen_at' => 'datetime',
    ];

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function printJobs()
    {
        return $this->hasMany(PrintJob::class);
    }

    public function printAttempts()
    {
        return $this->hasMany(PrintAttempt::class);
    }

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    public function isOnline(): bool
    {
        if (!$this->last_seen_at) {
            return false;
        }

        return $this->last_seen_at->gt(now()->subSeconds(config('pos.printing.station_offline_after_seconds')));
    }

    public function usablePrinterMap(): array
    {
        return collect($this->printer_map ?: [])
            ->map(fn ($printerName) => is_string($printerName) ? trim($printerName) : '')
            ->filter()
            ->all();
    }

    public function isConfigured(): bool
    {
        return $this->usablePrinterMap() !== [];
    }

    public function operationalStatus(bool $hasRecentFailedJobs = false): string
    {
        if (!$this->enabled) {
            return 'Disabled';
        }

        if (!$this->isConfigured()) {
            return 'Misconfigured';
        }

        if (!$this->last_seen_at) {
            return 'Never Connected';
        }

        if (!$this->isOnline()) {
            return 'Offline';
        }

        if (filled($this->last_error) || $hasRecentFailedJobs) {
            return 'Online / Warning';
        }

        return 'Online / Healthy';
    }

    public function hasPrinterKey(string $printerKey): bool
    {
        $map = $this->usablePrinterMap();

        return !empty($map[$printerKey]);
    }
}

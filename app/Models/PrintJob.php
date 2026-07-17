<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class PrintJob extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PRINTED = 'printed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'idempotency_key',
        'type',
        'copy_type',
        'printer_key',
        'source_type',
        'source_id',
        'payload_format',
        'payload',
        'status',
        'attempts',
        'max_attempts',
        'last_attempted_at',
        'locked_until',
        'print_station_id',
        'printed_at',
        'failed_at',
        'last_error',
        'retried_by',
        'retried_at',
    ];

    protected $casts = [
        'last_attempted_at' => 'datetime',
        'locked_until' => 'datetime',
        'printed_at' => 'datetime',
        'failed_at' => 'datetime',
        'retried_at' => 'datetime',
    ];

    public function printStation()
    {
        return $this->belongsTo(PrintStation::class);
    }

    public function source()
    {
        return $this->morphTo();
    }

    public function printAttempts()
    {
        return $this->hasMany(PrintAttempt::class);
    }

    public function retriedBy()
    {
        return $this->belongsTo(User::class, 'retried_by');
    }

    public function scopeAvailableForStation($query, PrintStation $station)
    {
        $printerKeys = collect($station->printer_map ?: [])
            ->filter()
            ->keys()
            ->values();

        return $query
            ->whereIn('printer_key', $printerKeys)
            ->whereRaw('attempts < COALESCE(max_attempts, 3)')
            ->where(function ($query) {
                $query
                    ->where('status', self::STATUS_PENDING)
                    ->orWhere(function ($query) {
                        $query
                            ->where('status', self::STATUS_PROCESSING)
                            ->where('locked_until', '<=', now());
                    });
            });
    }

    public function markPrinted(PrintStation $station): void
    {
        DB::transaction(function () use ($station) {
            $job = self::query()->lockForUpdate()->findOrFail($this->id);

            if ($job->status === self::STATUS_PRINTED) {
                return;
            }

            $completedAt = now();

            $job->currentAttempt($station)?->update([
                'status' => PrintAttempt::STATUS_PRINTED,
                'completed_at' => $completedAt,
                'error' => null,
            ]);

            $job->update([
                'status' => self::STATUS_PRINTED,
                'print_station_id' => $station->id,
                'printed_at' => $completedAt,
                'failed_at' => null,
                'locked_until' => null,
                'last_error' => null,
            ]);

            if (
                $job->type === 'bill'
                && $job->effectiveCopyType() === 'customer'
                && $job->source_type === Bill::class
                && $job->source_id
            ) {
                Bill::whereKey($job->source_id)
                    ->whereNull('printed_at')
                    ->update(['printed_at' => $completedAt]);
            }

            $station->update(['last_error' => null]);
        });

        $this->refresh();
    }

    public function markFailed(PrintStation $station, string $error): void
    {
        DB::transaction(function () use ($station, $error) {
            $job = self::query()->lockForUpdate()->findOrFail($this->id);
            $completedAt = now();

            $job->currentAttempt($station)?->update([
                'status' => PrintAttempt::STATUS_FAILED,
                'completed_at' => $completedAt,
                'error' => $error,
            ]);

            $job->update([
                'status' => self::STATUS_FAILED,
                'print_station_id' => $station->id,
                'failed_at' => $completedAt,
                'locked_until' => null,
                'last_error' => $error,
            ]);

            $station->update(['last_error' => $error]);
        });

        $this->refresh();
    }

    public function retry(User $actor): bool
    {
        $retried = DB::transaction(function () use ($actor) {
            $job = self::query()->lockForUpdate()->findOrFail($this->id);

            if ($job->status !== self::STATUS_FAILED) {
                return false;
            }

            $job->update([
                'status' => self::STATUS_PENDING,
                'max_attempts' => ($job->max_attempts ?? 3) + 1,
                'print_station_id' => null,
                'failed_at' => null,
                'locked_until' => null,
                'last_error' => null,
                'retried_by' => $actor->id,
                'retried_at' => now(),
            ]);

            return true;
        });

        $this->refresh();

        return $retried;
    }

    public function effectiveCopyType(): string
    {
        return $this->copy_type ?: 'customer';
    }

    public function displayCopyType(): string
    {
        if ($this->copy_type) {
            return $this->copy_type;
        }

        return $this->type === 'bill' ? 'customer' : '-';
    }

    public function isExhausted(): bool
    {
        return $this->status === self::STATUS_FAILED
            && $this->attempts >= ($this->max_attempts ?? 3);
    }

    public function isRetryEligible(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isReclaimable(): bool
    {
        return $this->status === self::STATUS_PENDING
            || (
                $this->status === self::STATUS_PROCESSING
                && $this->locked_until
                && $this->locked_until->lte(now())
            );
    }

    public function isUnclaimable(Collection|array $mappedPrinterKeys): bool
    {
        return $this->isReclaimable()
            && filled($this->printer_key)
            && !collect($mappedPrinterKeys)->contains($this->printer_key);
    }

    public function sourceIdentifier(): string
    {
        if ($this->source instanceof Bill) {
            return (string) ($this->source->invoice_no ?: $this->source->bill_id ?: "Bill #{$this->source_id}");
        }

        if ($this->source instanceof Order) {
            return (string) ($this->source->KOT ?: "Order #{$this->source_id}");
        }

        return $this->source_id ? "#{$this->source_id}" : '-';
    }

    public function requestedByName(): string
    {
        if ($this->source instanceof Order) {
            return $this->source->waiter?->name ?? 'System';
        }

        if ($this->source instanceof Bill) {
            return $this->source->lockedBy?->name
                ?? $this->source->orders->pluck('waiter.name')->filter()->unique()->implode(', ')
                ?: 'System';
        }

        return 'System';
    }

    private function currentAttempt(PrintStation $station): ?PrintAttempt
    {
        return $this->printAttempts()
            ->where('status', PrintAttempt::STATUS_PROCESSING)
            ->where('print_station_id', $station->id)
            ->latest('attempt_number')
            ->first();
    }
}

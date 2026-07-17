<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PrintAttempt extends Model
{
    use HasFactory;

    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PRINTED = 'printed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'print_job_id',
        'attempt_number',
        'print_station_id',
        'printer_name',
        'status',
        'claimed_at',
        'completed_at',
        'error',
    ];

    protected $casts = [
        'claimed_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function printJob()
    {
        return $this->belongsTo(PrintJob::class);
    }

    public function printStation()
    {
        return $this->belongsTo(PrintStation::class);
    }
}

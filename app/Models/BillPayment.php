<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class BillPayment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'received_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Finalized bill payments are immutable.'));
        static::deleting(fn () => throw new LogicException('Finalized bill payments cannot be deleted.'));
    }

    public function bill()
    {
        return $this->belongsTo(Bill::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}

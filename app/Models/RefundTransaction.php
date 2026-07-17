<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class RefundTransaction extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'recorded_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Refund transactions are immutable.'));
        static::deleting(fn () => throw new LogicException('Refund transactions cannot be deleted.'));
    }

    public function creditNote()
    {
        return $this->belongsTo(FiscalCreditNote::class, 'fiscal_credit_note_id');
    }
}

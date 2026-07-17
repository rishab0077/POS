<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class FiscalInvoiceItem extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
        'vat_rate' => 'decimal:3',
        'inventory_consumption' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Fiscal invoice items are immutable.'));
        static::deleting(fn () => throw new LogicException('Fiscal invoice items cannot be deleted.'));
    }

    public function snapshot()
    {
        return $this->belongsTo(FiscalInvoiceSnapshot::class, 'fiscal_invoice_snapshot_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class FiscalInvoiceSnapshot extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'invoice_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'service_charge' => 'decimal:2',
        'taxable_sales' => 'decimal:2',
        'tax_exempted_sales' => 'decimal:2',
        'vat_rate' => 'decimal:3',
        'vat' => 'decimal:2',
        'total_sales' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Fiscal invoice snapshots are immutable.'));
        static::deleting(fn () => throw new LogicException('Fiscal invoice snapshots cannot be deleted.'));
    }

    public function bill()
    {
        return $this->belongsTo(Bill::class);
    }

    public function items()
    {
        return $this->hasMany(FiscalInvoiceItem::class);
    }

    public function cbmsSubmission()
    {
        return $this->hasOne(CbmsSubmission::class);
    }

    public function creditNotes()
    {
        return $this->hasMany(FiscalCreditNote::class);
    }
}

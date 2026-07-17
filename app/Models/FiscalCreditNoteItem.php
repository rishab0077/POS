<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class FiscalCreditNoteItem extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
        'vat_rate' => 'decimal:3',
        'inventory_restore_quantities' => 'array',
        'inventory_restored_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $item) {
            if (array_diff(array_keys($item->getDirty()), ['inventory_restored_at', 'inventory_restored_by'])) {
                throw new LogicException('Fiscal credit-note items are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Fiscal credit-note items cannot be deleted.'));
    }

    public function creditNote()
    {
        return $this->belongsTo(FiscalCreditNote::class, 'fiscal_credit_note_id');
    }

    public function invoiceItem()
    {
        return $this->belongsTo(FiscalInvoiceItem::class, 'fiscal_invoice_item_id');
    }

    public function inventoryRestoredBy()
    {
        return $this->belongsTo(User::class, 'inventory_restored_by');
    }
}

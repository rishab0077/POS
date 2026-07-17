<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class FiscalCreditNote extends Model
{
    protected $guarded = [];

    protected $casts = [
        'issued_at' => 'datetime',
        'total_sales' => 'decimal:2',
        'taxable_sales' => 'decimal:2',
        'tax_exempted_sales' => 'decimal:2',
        'vat' => 'decimal:2',
        'payload' => 'array',
        'last_attempt_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $creditNote) {
            $deliveryFields = [
                'status', 'attempts', 'response_code', 'last_response', 'last_error',
                'last_attempt_at', 'submitted_at', 'updated_at',
            ];

            if (array_diff(array_keys($creditNote->getDirty()), $deliveryFields)) {
                throw new LogicException('Issued credit-note details are immutable.');
            }
        });

        static::deleting(fn () => throw new LogicException('Fiscal credit notes cannot be deleted.'));
    }

    public function snapshot()
    {
        return $this->belongsTo(FiscalInvoiceSnapshot::class, 'fiscal_invoice_snapshot_id');
    }

    public function operator()
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function items()
    {
        return $this->hasMany(FiscalCreditNoteItem::class);
    }

    public function transactions()
    {
        return $this->hasMany(RefundTransaction::class);
    }
}

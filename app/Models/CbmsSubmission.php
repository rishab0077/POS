<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CbmsSubmission extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'last_attempt_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];

    public function snapshot()
    {
        return $this->belongsTo(FiscalInvoiceSnapshot::class, 'fiscal_invoice_snapshot_id');
    }
}

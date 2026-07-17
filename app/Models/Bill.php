<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Bill extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'bill_id',
        'invoice_no',
        'fiscal_year',
        'customer_name',
        'buyer_name',
        'buyer_pan',
        'buyer_address',
        'table_id',
        'source_table_id',
        'bill_amount',
        'discount',
        'discount_type',
        'discount_value',
        'discount_reason',
        'discount_approved_by',
        'taxable_amount',
        'vat_amount',
        'service_charge_amount',
        'grand_total',
        'payment_method',
        'credit_customer_name',
        'credit_customer_contact',
        'credit_status',
        'credit_paid_amount',
        'credit_settled_at',
        'notes',
        'status',
        'printed_at',
        'printed_by',
        'locked_at',
        'locked_by',
    ];

    protected $casts = [
        'bill_amount' => 'decimal:2',
        'discount' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'taxable_amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'service_charge_amount' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'credit_paid_amount' => 'decimal:2',
        'credit_settled_at' => 'datetime',
        'printed_at' => 'datetime',
        'locked_at' => 'datetime',
    ];

    public function orders()
    {
        return $this->belongsToMany(Order::class, 'bill_orders')->withTimestamps();
    }

    public function billOrders()
    {
        return $this->hasMany(BillOrder::class);
    }

    public function table()
    {
        return $this->belongsTo(Table::class);
    }

    public function sourceTable()
    {
        return $this->belongsTo(Table::class, 'source_table_id');
    }

    public function printedBy()
    {
        return $this->belongsTo(User::class, 'printed_by');
    }

    public function lockedBy()
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function discountApprover()
    {
        return $this->belongsTo(User::class, 'discount_approved_by');
    }

    public function creditPayments()
    {
        return $this->hasMany(CreditPayment::class);
    }

    public function creditBalance(): float
    {
        return max(round((float) $this->grand_total - (float) $this->credit_paid_amount, 2), 0);
    }

    public function isLocked(): bool
    {
        return !is_null($this->locked_at) || !is_null($this->printed_at);
    }
}

<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'contact_person',
        'phone',
        'email',
        'vat_pan_no',
        'address',
        'opening_balance',
        'active',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'active' => 'boolean',
    ];

    public function purchaseInvoices()
    {
        return $this->hasMany(PurchaseInvoice::class);
    }

    public function payments()
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function stockItems()
    {
        return $this->hasMany(StockItem::class, 'default_supplier_id');
    }

    public function balance(): float
    {
        $postedTotal = (float) $this->purchaseInvoices()
            ->where('status', 'posted')
            ->sum('total_amount');
        $payments = (float) $this->payments()->sum('amount');

        return (float) $this->opening_balance + $postedTotal - $payments;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BillOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'bill_id',
        'order_id',
        'stock_deducted_at',
    ];

    protected $casts = [
        'stock_deducted_at' => 'datetime',
    ];

    public function bill()
    {
        return $this->belongsTo(Bill::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}

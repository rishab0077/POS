<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'menu_id',
        'order_id',
        'quantity',
        'unit_price',
        'loyalty_reward_quantity',
        'loyalty_original_unit_price',
        'loyalty_redeemed_by',
        'loyalty_redeemed_at',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'loyalty_original_unit_price' => 'decimal:2',
        'loyalty_redeemed_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function menu()
    {
        return $this->belongsTo(Menu::class);
    }

    public function loyaltyRedeemedBy()
    {
        return $this->belongsTo(User::class, 'loyalty_redeemed_by');
    }
}

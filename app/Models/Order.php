<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'KOT',
        'total',
        'table_id',
        'source_table_id',
        'status',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'cancellation_notes',
        'special_instructions',
        'order_type',
        'waiter_id',
    ];

    protected $casts = [
        'status' => OrderStatus::class,
        'order_type' => OrderType::class,
        'cancelled_at' => 'datetime',
    ];

    public function table()
    {
        return $this->belongsTo(Table::class, 'table_id');
    }

    public function sourceTable()
    {
        return $this->belongsTo(Table::class, 'source_table_id');
    }

    public function waiter()
    {
        return $this->belongsTo(User::class, 'waiter_id');
    }

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
    public function orderDetails()
    {
        return $this->hasMany(OrderDetail::class);
    }
}

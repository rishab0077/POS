<?php

namespace App\Http\Service;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order;

class OrderSyncService extends Service
{

    public function pendingOrders(bool $includeTakeaway)
    {
        return Order::whereIn('status', [OrderStatus::New->value, OrderStatus::Processing->value])
            ->when(!$includeTakeaway, fn ($query) => $query->whereNotNull('table_id'))
            ->orderBy('id')->get(['id', 'status']);
    }

    public function hasPickupOrderUpdates(int $lastOrderId, int $waiterId): bool
    {
        return Order::where('id', '>', $lastOrderId)
            ->where('order_type', OrderType::Takeaway->value)
            ->where('status', '!=', OrderStatus::New->value)
            ->where('waiter_id', $waiterId)
            ->exists();
    }
}

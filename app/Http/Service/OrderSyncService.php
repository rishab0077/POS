<?php

namespace App\Http\Service;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order;

class OrderSyncService extends Service
{

    public function hasNewOrders(int $lastOrderId, int $waiterId): bool
    {
        return Order::where('id', '>', $lastOrderId)
            ->where('status', '!=', OrderStatus::New->value)
            ->where('waiter_id', $waiterId)
            ->exists();
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

<?php

namespace App\Http\Service;

use App\Enums\OrderStatus;
use App\Helpers\KitchenHelper;
use App\Http\Service\Service;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Table;
use App\Models\BillOrder;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderService extends Service
{

    // create order
    // return KOT
    public function createOrder($orderData)
    {
        // create Order
        $kot = KitchenHelper::generateKOT();
        $total = $orderData->get("total");
        $tableId = $orderData->get("tableId");
        $sourceTableId = $orderData->get("sourceTableId") ?: $tableId;
        $status = $orderData->get("status");
        $specialInstructions = $orderData->get("specialInstructions");
        $orderType = $orderData->get("orderType");
        $waiterId = $orderData->get("waiterId");
        $orderItems = $orderData->get("orderItems");


        $orderObject = [
            "KOT" => $kot,
            "total" => $total,
            "table_id" => $tableId,
            "source_table_id" => $sourceTableId,
            "status" => $status,
            "special_instructions" => $specialInstructions,
            "order_type" => $orderType,
            "waiter_id" => $waiterId
        ];

        Log::info("Preparing to create order: " . json_encode($orderObject));

        try {
            DB::beginTransaction();

            $order = Order::create($orderObject);

            foreach ($orderItems as $key => $item) {
                $orderDetail = new OrderDetail([
                    'order_id' => $order->id,
                    'menu_id' => $item["id"],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['price'],
                ]);

                $order->orderDetails()->save($orderDetail);
            }

            DB::commit();

            Log::info("Order created successfully: " . $order);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error creating order: " . $e->getMessage());
            return $this->errorResponse("error", "Error creating order", $e->getMessage());
        }

        return $this->successResponse("success", "Order created successfully", $kot);
    }

    public function markAsServed($orderId)
    {
        return $this->changeStatus($orderId, OrderStatus::Served);
    }

    public function markAsClosed($orderId)
    {
        return $this->changeStatus($orderId, OrderStatus::Closed);
    }

    public function markAsProcessing($orderId)
    {
        return $this->changeStatus($orderId, OrderStatus::Processing);
    }

    public function markAsReadyForPickup($orderId)
    {
        return $this->changeStatus($orderId, OrderStatus::ReadyForPickup);
    }

    private function changeStatus($orderId, OrderStatus $status): Order
    {
        return DB::transaction(function () use ($orderId, $status) {
            $tableId = Order::whereKey($orderId)->value('table_id');
            if ($tableId) {
                Table::lockForUpdate()->findOrFail($tableId);
            }
            $order = Order::lockForUpdate()->findOrFail($orderId);
            if ($order->table_id != $tableId) {
                throw ValidationException::withMessages(['orderId' => 'This order was transferred. Refresh and try again.']);
            }
            if (in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Closed], true)) {
                throw ValidationException::withMessages(['orderId' => 'Closed or cancelled orders cannot change status.']);
            }
            if ($status === OrderStatus::Processing && !in_array($order->status, [OrderStatus::New, OrderStatus::Processing], true)) {
                throw ValidationException::withMessages(['orderId' => 'Only new orders can be accepted.']);
            }
            if ($status === OrderStatus::ReadyForPickup && $order->status === OrderStatus::Served) {
                throw ValidationException::withMessages(['orderId' => 'This order has already been served.']);
            }
            if ($status === OrderStatus::Closed && !BillOrder::where('order_id', $order->id)
                ->whereHas('bill', fn ($query) => $query->whereNotNull('locked_at')->orWhereNotNull('printed_at'))->exists()) {
                throw ValidationException::withMessages(['orderId' => 'Finalize the bill before closing this order.']);
            }
            $order->update(['status' => $status]);
            return $order;
        });
    }
}

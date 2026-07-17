<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Service\RestaurantService;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KitchenController extends Controller
{
    //
    public function index(RestaurantService $restaurantService)
    {
        $newOrders = Order::with(['orderDetails.menu.category', 'waiter', 'table'])
            ->where('status', OrderStatus::New->value)
            ->orderBy('created_at')
            ->get();

        $processingOrders = Order::with(['orderDetails.menu.category', 'waiter', 'table'])
            ->where('status', OrderStatus::Processing->value)
            ->orderBy('created_at')
            ->get();

        $pollIntervalSeconds = max(
            5,
            (int) ($restaurantService->getRestaurantDetails()?->pending_order_sync_time ?? 5)
        );

        return view("kitchen.index", compact('newOrders', 'processingOrders', 'pollIntervalSeconds'));
    }

    public function acceptOrder(Request $request)
    {
        $validated = $request->validate([
            'orderId' => ['required', 'integer', 'exists:orders,id'],
        ]);
        $order = Order::whereKey($validated['orderId'])
            ->where('status', OrderStatus::New->value)
            ->firstOrFail();

        $order->status = OrderStatus::Processing->value;
        $order->save();

        return response()->json(['message' => 'Order accepted successfully']);
    }

    public function discardOrder(Request $request)
    {
        $request->validate([
            'orderId' => ['required', 'exists:orders,id'],
            'reason' => ['required', 'string', 'max:100', Rule::in(config('pos.cancellation_reasons'))],
            'notes' => ['nullable', 'string'],
        ]);

        $order = Order::whereKey($request->orderId)
            ->whereIn('status', [OrderStatus::New->value, OrderStatus::Processing->value])
            ->firstOrFail();

        $order->update([
            'status' => OrderStatus::Cancelled->value,
            'cancelled_at' => now(),
            'cancelled_by' => auth()->id(),
            'cancellation_reason' => $request->reason,
            'cancellation_notes' => $request->notes,
        ]);

        return response()->json(['message' => 'Order cancelled successfully']);
    }


    public function getNewOrderComponent(Request $request)
    {
        $validated = $request->validate([
            'kot' => ['required', 'string', 'max:255'],
        ]);

        $order = Order::with(['orderDetails.menu.category', 'waiter', 'table'])
            ->where('KOT', $validated['kot'])
            ->where('status', OrderStatus::New->value)
            ->orderBy('created_at')
            ->firstOrFail();

        return response()->json($this->orderPayload(
            $order,
            'components.order.new-order-component-for-kitchen'
        ));
    }

    /**
     * Polling fallback for the kitchen display. WebSockets remain the primary
     * delivery path; returning all pending tickets lets the browser de-duplicate
     * by order ID without cursor races when both channels are active.
     */
    public function pendingOrders()
    {
        $pendingOrders = Order::with(['orderDetails.menu.category', 'waiter', 'table'])
            ->where('status', OrderStatus::New->value)
            ->orderBy('created_at')
            ->get()
            ->map(fn (Order $order) => $this->orderPayload(
                $order,
                'components.order.new-order-component-for-kitchen'
            ));

        $processingOrders = Order::with(['orderDetails.menu.category', 'waiter', 'table'])
            ->where('status', OrderStatus::Processing->value)
            ->orderBy('created_at')
            ->get()
            ->map(fn (Order $order) => $this->orderPayload(
                $order,
                'components.order.order-processing-component-for-kitchen'
            ));

        return response()->json([
            'status' => 'success',
            'orders' => $pendingOrders,
            'processingOrders' => $processingOrders,
        ]);
    }

    public function completeOrder(Request $request)
    {
        $validated = $request->validate([
            'orderId' => ['required', 'integer', 'exists:orders,id'],
        ]);
        $order = Order::whereKey($validated['orderId'])
            ->where('status', OrderStatus::Processing->value)
            ->firstOrFail();

        $order->status = OrderStatus::ReadyForPickup->value;
        $order->save();

        return response()->json(['message' => 'Order Completed successfully']);
    }

    private function orderPayload(Order $order, string $view): array
    {
        return [
            'id' => $order->id,
            'kot' => $order->KOT,
            'status' => $order->status->value,
            'html' => view($view, compact('order'))->render(),
        ];
    }
}

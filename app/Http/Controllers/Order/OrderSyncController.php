<?php

namespace App\Http\Controllers\Order;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Http\Service\OrderSyncService;

class OrderSyncController extends Controller
{
    private $orderSyncService;

    public function __construct(OrderSyncService $orderSyncService)
    {
        $this->orderSyncService = $orderSyncService;
    }


    public function syncPendingOrder(Request $request)
    {
        $validated = $request->validate([
            'lastOrderId' => ['nullable', 'integer', 'min:0'],
        ]);
        $lastOrderId = (int) ($validated['lastOrderId'] ?? 0);

        /** @var \App\Models\User $user */
        $user = Auth::user();

        $hasNewOrders = $this->orderSyncService->hasNewOrders($lastOrderId, $user->id);

        return $this->syncResponse($hasNewOrders);
    }

    public function syncPickUpOrder(Request $request)
    {
        $validated = $request->validate([
            'lastOrderId' => ['nullable', 'integer', 'min:0'],
        ]);
        $lastOrderId = (int) ($validated['lastOrderId'] ?? 0);

        /** @var \App\Models\User $user */
        $user = Auth::user();

        return $this->syncResponse(
            $this->orderSyncService->hasPickupOrderUpdates($lastOrderId, $user->id)
        );
    }

    private function syncResponse(bool $hasNewOrders)
    {
        return response()->json([
            'hasNewOrders' => $hasNewOrders,
            'status' => 'success',
            'message' => $hasNewOrders ? 'New Orders Found' : 'No New Orders Found',
        ]);
    }
}

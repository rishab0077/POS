<?php

namespace App\Http\Controllers\Order;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TableStatus;
use App\Enums\UserRole;
use App\Events\OrderSubmittedToKitchen;
use App\Helpers\BillHelper;
use App\Helpers\KitchenHelper;
use App\Helpers\RestaurantHelper;
use App\Helpers\TableHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrderSubmitRequest;
use App\Http\Service\OrderService;
use App\Http\Service\RestaurantService;
use App\Models\Menu;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\Table;
use Exception;
use Illuminate\Support\Facades\Log;
use App\Services\PrintJobService;
use App\Services\AuditLogger;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    private $orderService;
    protected $restaurantService;
    protected $printJobService;

    public function __construct(RestaurantService $restaurantService, OrderService $orderService, PrintJobService $printJobService)
    {
        $this->restaurantService = $restaurantService;

        $this->orderService = $orderService;
        $this->printJobService = $printJobService;
    }

    public function submit(OrderSubmitRequest $request, AuditLogger $audit)
    {
        $source = $request->source;
        $tableId = $request->tableId;
        $specialInstructions =  $request->specialInstructions ? implode(',',  $request->specialInstructions) :  null;
        $isTableOrder = true;
        $isPickUpOrder = $request->boolean('isPickUpOrder');
        $kitchenModuleEnabled = $this->restaurantService->isKitchenModuleEnabled();

        $this->authorizeOrderSource($source);

        if ($request->filled('loyalty_rewards') && ($source !== 'pos' || (!$isPickUpOrder && !$request->boolean('billTable')))) {
            throw ValidationException::withMessages([
                'loyalty_rewards' => 'Loyalty rewards can only be applied during final payment.',
            ]);
        }

        if (!$isPickUpOrder) {
            $table = Table::findOrFail($tableId);

            if (!in_array($table->status, [TableStatus::Available, TableStatus::Running, TableStatus::RunningKOT], true)) {
                throw ValidationException::withMessages([
                    'tableId' => 'This table is finalized or unavailable. Close it before starting another order.',
                ]);
            }
        }

        $order = $this->trustedOrder($request->input('order.orderItems', []));
        if ($isPickUpOrder || $request->boolean('billTable')) {
            $this->validateLoyaltyRewards(
                $request->input('loyalty_rewards', []),
                $order['orderItems'],
                $isPickUpOrder ? null : (int) $tableId
            );
        }
        $buyerData = $request->only(['buyer_name', 'buyer_pan', 'buyer_address', 'credit_customer_name', 'credit_customer_contact']);

        // if pick up order then set isTableOrder to false
        if ($isPickUpOrder) {
            $isTableOrder = false;
        }

        $commonData = collect([
            'tableId' => $tableId,
            'specialInstructions' => $specialInstructions,
            'isPickUpOrder' => $isPickUpOrder,
            'isTableOrder' => $isTableOrder,
        ]);

        if ($source === "waiter") {
            $orderData = $this->processWaiterOrder($request, $commonData, $order);
        } else if ($source === "pos") {
            $orderData = $this->processPOSOrder($request, $commonData, $order);
        }

        // New Order if Kitchen Module is enabled else by default order status marked as served
        $orderStatus = $kitchenModuleEnabled ? OrderStatus::New : OrderStatus::Served;
        $orderData->put('status', $orderStatus);


        // insert order
        $response = $this->orderService->createOrder($orderData);

        // if order submission failed
        if ($response['status'] === "error") {
            return response()->json($response, 500);
        }

        $kot = $response['data'];

        // Table Marking

        if ($isTableOrder) {
            TableHelper::markTableAsRunning($tableId);
        }


        // Send event to kitchen module
        if ($kitchenModuleEnabled) {
            try {
                event(new OrderSubmittedToKitchen($kot));
            } catch (Exception $e) {
                Log::error("Unable to connect with pusher api - internet issue");
            }
        }


        // Billing Process

        $billId = null;
        $discount = 0;
        $paymentMethod = $request->paymentMethod;
        $billTable = $request->boolean('billTable');

        if ($isPickUpOrder) {
            // Create and finalize takeaway bills immediately.
            $billId = BillHelper::createPickUpBill($kot);
            $bill = BillHelper::finalizeBill($billId, $paymentMethod, $buyerData, [], true, $request->input('payments', []), $request->input('loyalty_rewards', []));
            $billId = $bill->id;

            if ($this->restaurantService->hasEnabledPrintStationFor('counter')) {
                $this->printJobService->queueBillCopies($billId, $request->input('print_copies'));
            }
        }

        if ($isTableOrder && $billTable) {

            $billId = BillHelper::createTableBill($tableId);
            $bill = BillHelper::finalizeBill($billId, $paymentMethod, $buyerData, [], false, $request->input('payments', []), $request->input('loyalty_rewards', []));
            $billId = $bill->id;
            TableHelper::markTableAsFinalized($tableId);

            // if printBill is enabled then print bill
            if ($this->restaurantService->hasEnabledPrintStationFor('counter')) {
                $this->printJobService->queueBillCopies($billId, $request->input('print_copies'));
            }
        }

        if ($billId !== null) {
            $bill->load('orders.orderDetails.menu');
            $audit->record('bill_finalized', 'billing', [
                'subject' => $bill,
                'after' => $bill->only(['id', 'invoice_no', 'table_id', 'grand_total', 'payment_method', 'locked_at', 'locked_by']),
                'metadata' => [
                    'summary' => "Bill {$bill->invoice_no} finalized.",
                    'payments' => $bill->payments->map->only(['payment_method', 'amount', 'reference_no'])->all(),
                ],
            ]);

            $this->auditLoyaltyRewards($bill, $audit);
        }

        if (
            $this->restaurantService->hasEnabledPrintStationFor('kitchen')
            && KitchenHelper::getKOTOrders($kot, 'kitchen')->isNotEmpty()
        ) {
            $this->printJobService->queueKot($kot, $billId, 'kitchen', 'KOT');
        }

        if (
            $this->restaurantService->hasEnabledPrintStationFor('bar')
            && KitchenHelper::getKOTOrders($kot, 'bar')->isNotEmpty()
        ) {
            $this->printJobService->queueKot($kot, $billId, 'bar', 'BOT');
        }

        return response()->json(["status" => "success", "message" => "order Submitted successfully"]);
    }

    // Process Waiter Order
    private function processWaiterOrder(Request $request, $commonData, array $order)
    {
        $waiterSpecificData = collect([
            'waiterId' => auth()->user()->id,
            'orderItems' => $order["orderItems"],
            'total' => $order["total"],
            'orderType' => OrderType::DineIn->value,

        ]);

        return $commonData->merge($waiterSpecificData);
    }

    // Process POS Order
    private function processPOSOrder(Request $request, $commonData, array $order)
    {
        $orderType = $commonData['isPickUpOrder'] ? OrderType::Takeaway->value : OrderType::DineIn->value;

        $POSSpecificData = collect([
            'waiterId' => auth()->user()->id,
            'orderItems' => $order["orderItems"],
            'total' => $order["total"],
            'orderType' => $orderType,

        ]);

        return $commonData->merge($POSSpecificData);
    }

    private function authorizeOrderSource(string $source): void
    {
        $user = auth()->user();

        if ($source === 'pos' && !$user?->canUsePos()) {
            abort(403, 'You are not allowed to submit POS orders.');
        }

        if ($source === 'waiter' && !$user?->hasPermission(UserRole::Waiter)) {
            abort(403, 'You are not allowed to submit waiter orders.');
        }
    }

    private function trustedOrder(array $submittedItems): array
    {
        $quantities = collect($submittedItems)
            ->mapWithKeys(fn (array $item) => [(int) $item['id'] => (int) $item['quantity']]);
        $menus = Menu::with('category')->whereIn('id', $quantities->keys())->get()->keyBy('id');

        if ($menus->count() !== $quantities->count()) {
            throw ValidationException::withMessages([
                'order.orderItems' => 'One or more menu items are no longer available.',
            ]);
        }

        $items = $quantities->map(function (int $quantity, int $menuId) use ($menus) {
            $menu = $menus->get($menuId);
            $price = round((float) $menu->price, 2);

            return [
                'id' => $menu->id,
                'name' => $menu->name,
                'quantity' => $quantity,
                'price' => $price,
                'total' => round($price * $quantity, 2),
            ];
        })->values()->all();

        $trustedOrder = [
            'orderItems' => $items,
            'total' => round((float) collect($items)->sum('total'), 2),
            'discount' => 0,
            'grandtotal' => round((float) collect($items)->sum('total'), 2),
        ];

        return $trustedOrder;
    }

    private function validateLoyaltyRewards(array $rewards, array $newItems, ?int $tableId): void
    {
        if ($rewards === []) {
            return;
        }

        $requested = collect($rewards)
            ->mapWithKeys(fn (array $reward) => [(int) $reward['menu_id'] => (int) $reward['quantity']]);
        $available = collect($newItems)
            ->mapWithKeys(fn (array $item) => [(int) $item['id'] => (int) $item['quantity']]);

        if ($tableId !== null) {
            BillHelper::processTableBill($tableId)
                ->load('orderDetails')
                ->flatMap->orderDetails
                ->each(function ($detail) use ($available) {
                    $available->put(
                        (int) $detail->menu_id,
                        (int) $available->get((int) $detail->menu_id, 0) + (int) $detail->quantity
                    );
                });
        }

        $eligibleMenus = Menu::with('category')
            ->whereIn('id', $requested->keys())
            ->get()
            ->keyBy('id');

        foreach ($requested as $menuId => $quantity) {
            $menu = $eligibleMenus->get($menuId);

            if (!$menu?->category->contains('loyalty_eligible', true)) {
                throw ValidationException::withMessages([
                    'loyalty_rewards' => 'One or more selected items are not eligible for a loyalty reward.',
                ]);
            }

            if ($quantity > (int) $available->get($menuId, 0)) {
                throw ValidationException::withMessages([
                    'loyalty_rewards' => 'Reward quantity cannot exceed the ordered quantity.',
                ]);
            }
        }
    }

    private function auditLoyaltyRewards($bill, AuditLogger $audit): void
    {
        $items = $bill->orders->flatMap->orderDetails
            ->where('loyalty_reward_quantity', '>', 0);

        if ($items->isEmpty()) {
            return;
        }

        $audit->record('loyalty_reward_applied', 'billing', [
            'subject' => $bill,
            'metadata' => [
                'summary' => 'Physical stamp-card rewards applied during final payment.',
                'items' => $items->map(fn ($item) => [
                    'menu_id' => $item->menu_id,
                    'item_name' => $item->menu?->name,
                    'quantity' => (int) $item->loyalty_reward_quantity,
                    'original_unit_price' => (float) $item->loyalty_original_unit_price,
                ])->values()->all(),
            ],
        ]);
    }

    public function markAsServed(Request $request)
    {
        abort_unless(auth()->user()?->canMarkOrderServed(), 403);
        $request->validate(['orderId' => ['required', 'integer', 'exists:orders,id']]);

        try {
            $order = $this->orderService->markAsServed($request->orderId);
            return response()->json(['status' => 'success', 'message' => 'Order served successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }
    }
    public function markAsPrepared(Request $request)
    {
        abort_unless(auth()->user()?->canMarkOrderPrepared(), 403);
        $request->validate(['orderId' => ['required', 'integer', 'exists:orders,id']]);

        try {
            $order = $this->orderService->markAsReadyForPickup($request->orderId);
            return response()->json(['status' => 'success', 'message' => 'Order prepared successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }
    }

    public function markAsClosed(Request $request)
    {
        abort_unless(auth()->user()?->canCloseOrder(), 403);
        $request->validate(['orderId' => ['required', 'integer', 'exists:orders,id']]);

        try {
            $order = $this->orderService->markAsClosed($request->orderId);
            return response()->json(['status' => 'success', 'message' => 'Order closed successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }
    }

    public function KOTView()
    {
        abort_unless(auth()->user()?->canViewOperations(), 403);

        /** @var \App\User */
        $waiter = auth()->user();

        $tables = Table::where('status', TableStatus::Running)->get();

        $orders = Order::with('orderDetails.menu')
            ->whereIn('status', [OrderStatus::New->value, OrderStatus::Processing->value])
            ->orderBy('created_at', 'desc');

        $orders = $orders->get();

        $orderSyncTime = RestaurantHelper::getCachedRestaurantDetails()->pending_order_sync_time * 1000;

        return view('orders.kot-view', compact('tables', 'orders', 'orderSyncTime'));
    }
}

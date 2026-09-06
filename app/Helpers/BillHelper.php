<?php


namespace App\Helpers;

use App\Modules\Inventory\Services\StockDeductionService;
use App\Enums\OrderType;
use App\Models\Bill;
use App\Models\BillOrder;
use App\Models\FiscalInvoiceSnapshot;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Services\BusinessConfigurationService;
use App\Services\CbmsService;
use App\Services\NepalFiscalYearService;
use App\Services\VatCalculatorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BillHelper
{

    public static function generateBillID()
    {
        $todayStart = now()->startOfDay();
        $todayEnd = now()->endOfDay();

        $datePart = now()->format('Ymd');

        $orderNumber = Bill::whereDate('created_at', '>=', $todayStart)->whereDate('created_at', '<=', $todayEnd)->count() + 1;

        $billId = $datePart . $orderNumber;

        return  $billId;
    }

    public static function createPickUpBill($kot, $notes = null, $paymentMethod = null, $discount = 0, array $buyerData = [])
    {
        $orders = self::processPickUpBill($kot);

        $billData = collect([
            'orders' => $orders,
            'notes' => $notes,
            'orderType' => OrderType::Takeaway,
            'discount' => $discount,
            'paymentMethod' => $paymentMethod,
            'buyerData' => $buyerData,
        ]);

        $billId =  self::insertBill($billData);

        return $billId;
    }

    public static function createTableBill($tableId, $notes = null, $paymentMethod = null, $discount = 0, array $buyerData = [], ?int $sourceTableId = null)
    {
        $existingBill = self::editableTableBillQuery($tableId, $sourceTableId)->first();

        $orders = self::processTableBill($tableId, $sourceTableId);

        if ($existingBill) {
            self::updateExistingBill($existingBill, $orders, $notes, $discount, $paymentMethod, $buyerData);
            return $existingBill->id;
        } else {
            $billData = collect([
                'tableId' => $tableId,
                'sourceTableId' => $sourceTableId,
                'orders' => $orders,
                'notes' => $notes,
                'orderType' => OrderType::DineIn,
                'discount' => $discount,
                'paymentMethod' => $paymentMethod,
                'buyerData' => $buyerData,
            ]);

            $billId =  self::insertBill($billData);

            return $billId;
        }
    }

    public static function updateExistingBill($existingBill, $newOrders, $notes, $discount, $paymentMethod = null, array $buyerData = [])
    {
        if ($existingBill->isLocked()) {
            throw ValidationException::withMessages([
                'bill' => 'This bill has already been finalized and cannot be edited.',
            ]);
        }

        DB::transaction(function () use ($existingBill, $newOrders, $notes, $discount, $paymentMethod, $buyerData) {
            if ($newOrders->isEmpty()) {
                throw ValidationException::withMessages([
                    'orders' => 'There are no active orders to bill.',
                ]);
            }

            self::releaseOrdersFromEditableBills($newOrders->pluck('id')->all(), $existingBill->id);
            BillOrder::where('bill_id', $existingBill->id)->delete();

            foreach ($newOrders as $order) {
                BillOrder::create([
                    'bill_id' => $existingBill->id,
                    'order_id' => $order->id,
                ]);
            }

            $existingBill->refresh()->load('orders');
            $newTotal = $existingBill->orders->sum('total');
            $discountPayload = self::discountPayload((float) $newTotal, self::legacyDiscountData($discount));
            $tax = app(VatCalculatorService::class)->calculate((float) $newTotal, (float) $discountPayload['discount']);
            $buyer = self::buyerPayload($buyerData);

            $existingBill->update(array_merge([
                'bill_amount' => $newTotal,
                'grand_total' => $tax['grand_total'],
                'discount' => $discountPayload['discount'],
                'discount_type' => $discountPayload['discount_type'],
                'discount_value' => $discountPayload['discount_value'],
                'taxable_amount' => $tax['taxable_amount'],
                'vat_amount' => $tax['vat_amount'],
                'service_charge_amount' => $tax['service_charge_amount'],
                'payment_method' => $paymentMethod ?: $existingBill->payment_method,
                'notes' => $notes,
            ], $buyer));
        });
    }

    public static function processPickUpBill($kot)
    {
        $orders = Order::where('kot', $kot)->get();
        return $orders;
    }

    public static function processTableBill($tableId, ?int $sourceTableId = null)
    {
        $finalizedOrderIds = BillOrder::whereHas('bill', function ($query) {
            $query->where('status', 'closed')
                ->orWhereNotNull('locked_at')
                ->orWhereNotNull('printed_at');
        })->pluck('order_id')->toArray();

        $orders = Order::where('table_id', $tableId)
            ->whereNotIn('status', ['closed', 'cancelled'])
            ->whereNotIn('id', $finalizedOrderIds);

        if ($sourceTableId) {
            $orders->where(function ($query) use ($sourceTableId) {
                $query->where('source_table_id', $sourceTableId)
                    ->orWhere(function ($fallback) use ($sourceTableId) {
                        $fallback->whereNull('source_table_id')
                            ->where('table_id', $sourceTableId);
                    });
            });
        }

        return $orders->get();
    }

    private static function insertBill($billData)
    {
        return DB::transaction(function () use ($billData) {
            $tableId = $billData->get('tableId');
            $sourceTableId = $billData->get('sourceTableId');
            $paymentMethod = $billData->get('paymentMethod');
            $notes = $billData->get('notes');
            $discount = $billData->get('discount');
            $orders = $billData->get('orders');

            if ($orders->isEmpty()) {
                throw ValidationException::withMessages([
                    'orders' => 'There are no active orders to bill.',
                ]);
            }

            $total = $orders->sum('total');
            $discountPayload = self::discountPayload((float) $total, self::legacyDiscountData($discount));
            $tax = app(VatCalculatorService::class)->calculate((float) $total, (float) $discountPayload['discount']);
            $buyer = self::buyerPayload($billData->get('buyerData', []));

            $billId = BillHelper::generateBillID();

            $billObject = array_merge([
                'bill_id' => $billId,
                'table_id' => $tableId,
                'source_table_id' => $sourceTableId,
                'bill_amount' => $total,
                'discount' => $discountPayload['discount'],
                'discount_type' => $discountPayload['discount_type'],
                'discount_value' => $discountPayload['discount_value'],
                'taxable_amount' => $tax['taxable_amount'],
                'vat_amount' => $tax['vat_amount'],
                'service_charge_amount' => $tax['service_charge_amount'],
                'grand_total' => $tax['grand_total'],
                'payment_method' => $paymentMethod,
                'notes' => $notes,
            ], $buyer);

            $bill = Bill::create($billObject);
            self::releaseOrdersFromEditableBills($orders->pluck('id')->all(), $bill->id);

            foreach ($orders as $order) {
                BillOrder::create([
                    'bill_id' => $bill->id,
                    'order_id' => $order->id,
                ]);
            }

            return $bill->id;
        });
    }

    public static function finalizeBill(
        int $billId,
        string $paymentMethod,
        array $buyerData = [],
        array $discountData = [],
        bool $closeBill = false,
        array $payments = [],
        array $loyaltyRewards = []
    ): Bill
    {
        return DB::transaction(function () use ($billId, $paymentMethod, $buyerData, $discountData, $closeBill, $payments, $loyaltyRewards) {
            $bill = Bill::with('orders')->lockForUpdate()->findOrFail($billId);

            if ($bill->isLocked()) {
                throw ValidationException::withMessages([
                    'bill' => 'This bill has already been finalized and cannot be edited.',
                ]);
            }

            self::applyLoyaltyRewards($bill, $loyaltyRewards);
            $bill->load('orders');
            $total = (float) $bill->orders->sum('total');
            $discountPayload = self::discountPayload($total, $discountData);
            $tax = app(VatCalculatorService::class)->calculate($total, (float) $discountPayload['discount']);
            $buyer = self::buyerPayload($buyerData);
            $credit = self::creditPayload($paymentMethod, $buyerData);
            $discountApproval = self::discountApprovalPayload((float) $discountPayload['discount'], $buyerData);
            $paymentAllocations = self::paymentAllocations($paymentMethod, $payments, $tax['grand_total']);

            self::validateBuyerDetails($tax['grand_total'], $buyer);

            $invoice = app(NepalFiscalYearService::class)->nextInvoiceNumber();

            $bill->update(array_merge([
                'invoice_no' => $invoice['invoice_no'],
                'fiscal_year' => $invoice['fiscal_year'],
                'bill_amount' => $total,
                'discount' => $discountPayload['discount'],
                'discount_type' => $discountPayload['discount_type'],
                'discount_value' => $discountPayload['discount_value'],
                'taxable_amount' => $tax['taxable_amount'],
                'vat_amount' => $tax['vat_amount'],
                'service_charge_amount' => $tax['service_charge_amount'],
                'grand_total' => $tax['grand_total'],
                'payment_method' => $paymentMethod,
                'status' => $closeBill ? 'closed' : $bill->status,
                'locked_at' => now(),
                'locked_by' => auth()->id(),
            ], $buyer, $credit, $discountApproval));

            $bill = $bill->fresh();
            $bill->payments()->createMany($paymentAllocations);
            $inventoryConsumption = self::deductStock($bill);
            self::snapshotFiscalInvoice($bill, $inventoryConsumption);

            return $bill->load('payments');
        });
    }

    private static function applyLoyaltyRewards(Bill $bill, array $rewards): void
    {
        if ($rewards !== [] && !auth()->user()?->canUsePos()) {
            throw ValidationException::withMessages([
                'loyalty_rewards' => 'Only a POS cashier can apply loyalty rewards during final payment.',
            ]);
        }

        $requested = collect($rewards)
            ->mapWithKeys(fn (array $reward) => [(int) $reward['menu_id'] => (int) $reward['quantity']]);
        $details = OrderDetail::with('menu.category')
            ->whereIn('order_id', $bill->orders->pluck('id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($requested as $menuId => $quantity) {
            $matching = $details->where('menu_id', $menuId);

            if ($matching->isEmpty() || !$matching->first()?->menu?->category->contains('loyalty_eligible', true)) {
                throw ValidationException::withMessages([
                    'loyalty_rewards' => 'One or more selected items are not eligible for a loyalty reward.',
                ]);
            }

            if ($quantity < 1 || $quantity > (int) $matching->sum('quantity')) {
                throw ValidationException::withMessages([
                    'loyalty_rewards' => 'Reward quantity cannot exceed the ordered quantity.',
                ]);
            }
        }

        $remaining = $requested->all();
        foreach ($details as $detail) {
            $rewardQuantity = min((int) ($remaining[$detail->menu_id] ?? 0), (int) $detail->quantity);
            $remaining[$detail->menu_id] = (int) ($remaining[$detail->menu_id] ?? 0) - $rewardQuantity;
            $detail->update([
                'loyalty_reward_quantity' => $rewardQuantity,
                'loyalty_original_unit_price' => $rewardQuantity > 0 ? $detail->unit_price : null,
                'loyalty_redeemed_by' => $rewardQuantity > 0 ? auth()->id() : null,
                'loyalty_redeemed_at' => $rewardQuantity > 0 ? now() : null,
            ]);
        }

        foreach ($bill->orders as $order) {
            $order->update([
                'total' => round((float) $details->where('order_id', $order->id)->sum(
                    fn (OrderDetail $detail) => (float) $detail->unit_price
                        * ((int) $detail->quantity - (int) $detail->loyalty_reward_quantity)
                ), 2),
            ]);
        }
    }

    public static function getBillOrders($billId)
    {
        $billDetails = Bill::where('id', $billId)
            ->with('fiscalSnapshot.items')
            ->with('orders')
            ->with('orders.orderDetails')
            ->with('orders.orderDetails.menu')
            ->with('table')
            ->first();


        $orderDetails = collect([]);

        if ($billDetails->fiscalSnapshot) {
            foreach ($billDetails->fiscalSnapshot->items as $item) {
                $displayName = $item->item_name . ($item->pricing_reason === 'physical_stamp_card' ? ' (Loyalty Reward)' : '');
                self::addOrderDetail(
                    $orderDetails,
                    $displayName,
                    (float) $item->quantity,
                    (float) $item->unit_price,
                    (float) $item->line_total,
                    $item->pricing_reason === 'physical_stamp_card',
                    $item->original_unit_price ? (float) $item->original_unit_price : null
                );
            }

            return $orderDetails;
        }

        foreach ($billDetails->orders as $order) {
            foreach ($order->orderDetails as $orderDetail) {
                $itemName = $orderDetail->menu?->name ?? 'Deleted menu item';
                $quantity = $orderDetail->quantity;
                $price = (float) ($orderDetail->unit_price ?? $orderDetail->menu?->price ?? 0);
                $rewardQuantity = min((int) $orderDetail->loyalty_reward_quantity, (int) $quantity);
                $paidQuantity = (int) $quantity - $rewardQuantity;

                if ($paidQuantity > 0) {
                    self::addOrderDetail($orderDetails, $itemName, $paidQuantity, $price, $paidQuantity * $price);
                }

                if ($rewardQuantity > 0) {
                    self::addOrderDetail(
                        $orderDetails,
                        $itemName . ' (Loyalty Reward)',
                        $rewardQuantity,
                        0,
                        0,
                        true,
                        (float) ($orderDetail->loyalty_original_unit_price ?? $price)
                    );
                }
            }
        }

        return $orderDetails;
    }

    public static function getLatestBillId($tableId)
    {
        $bill = Bill::where('table_id', $tableId)->latest()->firstOrFail();
        return $bill->id;
    }

    public static function activeTableOrdersFinalized(int $tableId): bool
    {
        $activeOrderIds = Order::where('table_id', $tableId)
            ->whereNotIn('status', ['closed', 'cancelled'])
            ->pluck('id');

        if ($activeOrderIds->isEmpty()) {
            return false;
        }

        $finalizedOrderCount = BillOrder::whereIn('order_id', $activeOrderIds)
            ->whereHas('bill', function ($query) {
                $query->where('status', 'closed')
                    ->orWhereNotNull('locked_at')
                    ->orWhereNotNull('printed_at');
            })
            ->distinct()
            ->count('order_id');

        return $finalizedOrderCount === $activeOrderIds->count();
    }

    public static function editableTableBillQuery(int $tableId, ?int $sourceTableId = null)
    {
        return Bill::where('table_id', $tableId)
            ->where('status', 'open')
            ->whereNull('locked_at')
            ->whereNull('printed_at')
            ->when($sourceTableId, function ($query) use ($sourceTableId) {
                $query->where('source_table_id', $sourceTableId);
            }, function ($query) {
                $query->whereNull('source_table_id');
            });
    }

    private static function releaseOrdersFromEditableBills(array $orderIds, ?int $exceptBillId = null): void
    {
        if (empty($orderIds)) {
            return;
        }

        $detachedBillIds = BillOrder::whereIn('order_id', $orderIds)
            ->whereHas('bill', function ($query) {
                $query->where('status', 'open')
                    ->whereNull('locked_at')
                    ->whereNull('printed_at');
            })
            ->when($exceptBillId, function ($query) use ($exceptBillId) {
                $query->where('bill_id', '!=', $exceptBillId);
            })
            ->pluck('bill_id')
            ->unique()
            ->values()
            ->all();

        BillOrder::whereIn('order_id', $orderIds)
            ->whereHas('bill', function ($query) {
                $query->where('status', 'open')
                    ->whereNull('locked_at')
                    ->whereNull('printed_at');
            })
            ->when($exceptBillId, function ($query) use ($exceptBillId) {
                $query->where('bill_id', '!=', $exceptBillId);
            })
            ->delete();

        self::deleteEmptyEditableBills($detachedBillIds);
    }

    private static function deleteEmptyEditableBills(array $billIds): void
    {
        if (empty($billIds)) {
            return;
        }

        Bill::whereIn('id', $billIds)
            ->where('status', 'open')
            ->whereNull('locked_at')
            ->whereNull('printed_at')
            ->whereDoesntHave('billOrders')
            ->delete();
    }

    private static function buyerPayload(array $buyerData): array
    {
        return [
            'buyer_name' => $buyerData['buyer_name'] ?? null,
            'buyer_pan' => $buyerData['buyer_pan'] ?? null,
            'buyer_address' => $buyerData['buyer_address'] ?? null,
        ];
    }

    private static function creditPayload(?string $paymentMethod, array $data): array
    {
        if ($paymentMethod !== 'credit') {
            return [
                'credit_customer_name' => null,
                'credit_customer_contact' => null,
                'credit_status' => null,
                'credit_paid_amount' => 0,
            ];
        }

        if (empty($data['credit_customer_name'])) {
            throw ValidationException::withMessages([
                'credit_customer_name' => 'Credit customer name is required.',
            ]);
        }

        return [
            'credit_customer_name' => $data['credit_customer_name'],
            'credit_customer_contact' => $data['credit_customer_contact'] ?? null,
            'credit_status' => 'open',
            'credit_paid_amount' => 0,
        ];
    }

    private static function paymentAllocations(string $paymentMethod, array $payments, float $grandTotal): array
    {
        $totalCents = (int) round($grandTotal * 100);

        if ($paymentMethod === 'credit') {
            if ($payments !== []) {
                throw ValidationException::withMessages(['payments' => 'Credit bills cannot include direct payment allocations.']);
            }

            return [];
        }

        if ($paymentMethod !== 'split') {
            if ($payments !== []) {
                throw ValidationException::withMessages(['payments' => 'Payment allocations are only accepted for split payments.']);
            }

            if (!array_key_exists($paymentMethod, config('pos.payments'))) {
                throw ValidationException::withMessages(['paymentMethod' => 'Select a supported payment method.']);
            }

            if ($totalCents === 0) {
                return [];
            }

            return [[
                'payment_method' => $paymentMethod,
                'amount' => self::centsToMoney($totalCents),
                'received_at' => now(),
                'recorded_by' => auth()->id(),
            ]];
        }

        if ($totalCents === 0) {
            throw ValidationException::withMessages(['payments' => 'A zero-total bill cannot be split.']);
        }

        if (count($payments) !== 2) {
            throw ValidationException::withMessages(['payments' => 'A split payment requires exactly two allocations.']);
        }

        $allowedMethods = array_diff(array_keys(config('pos.payments')), ['credit']);
        $allocations = [];

        foreach (array_values($payments) as $index => $payment) {
            $method = $payment['method'] ?? null;

            if (!is_string($method) || !in_array($method, $allowedMethods, true)) {
                throw ValidationException::withMessages([
                    "payments.{$index}.method" => 'Select a supported cash, card, or wallet method.',
                ]);
            }

            $amountCents = self::moneyToCents($payment['amount'] ?? null, "payments.{$index}.amount");

            if ($amountCents <= 0) {
                throw ValidationException::withMessages([
                    "payments.{$index}.amount" => 'Each split amount must be greater than zero.',
                ]);
            }

            $reference = isset($payment['reference_no']) ? trim((string) $payment['reference_no']) : null;
            if ($reference !== null && mb_strlen($reference) > 100) {
                throw ValidationException::withMessages([
                    "payments.{$index}.reference_no" => 'Payment references may not exceed 100 characters.',
                ]);
            }

            $allocations[] = [
                'payment_method' => $method,
                'amount' => self::centsToMoney($amountCents),
                'reference_no' => $reference ?: null,
                'received_at' => now(),
                'recorded_by' => auth()->id(),
            ];
        }

        if ($allocations[0]['payment_method'] === $allocations[1]['payment_method']) {
            throw ValidationException::withMessages(['payments' => 'Split payment methods must be different.']);
        }

        $allocatedCents = array_sum(array_map(
            fn (array $allocation) => self::moneyToCents($allocation['amount'], 'payments'),
            $allocations
        ));

        if ($allocatedCents !== $totalCents) {
            throw ValidationException::withMessages([
                'payments' => 'Split payment amounts must equal the exact bill total of NPR ' . self::centsToMoney($totalCents) . '.',
            ]);
        }

        return $allocations;
    }

    private static function moneyToCents(mixed $amount, string $field): int
    {
        $value = trim((string) $amount);

        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
            throw ValidationException::withMessages([$field => 'Enter a valid amount with no more than two decimal places.']);
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private static function centsToMoney(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private static function validateBuyerDetails(float $grandTotal, array $buyer): void
    {
        $threshold = (float) config('pos.invoice.buyer_pan_required_above', 10000);

        if ($grandTotal <= $threshold) {
            return;
        }

        $missing = [];

        if (empty($buyer['buyer_name'])) {
            $missing['buyer_name'] = 'Buyer name is required for invoices above NPR 10,000.';
        }

        if (empty($buyer['buyer_pan'])) {
            $missing['buyer_pan'] = 'Buyer PAN is required for invoices above NPR 10,000.';
        }

        if ($missing) {
            throw ValidationException::withMessages($missing);
        }
    }

    private static function discountApprovalPayload(float $discount, array $data): array
    {
        if ($discount <= 0) {
            return [
                'discount_reason' => null,
                'discount_approved_by' => null,
            ];
        }

        if (!auth()->user()?->canApplyDiscount()) {
            throw ValidationException::withMessages([
                'discount' => 'Only Admin, Owner, or Manager can apply discounts.',
            ]);
        }

        if (empty($data['discount_reason'])) {
            throw ValidationException::withMessages([
                'discount_reason' => 'Discount reason is required.',
            ]);
        }

        return [
            'discount_reason' => $data['discount_reason'],
            'discount_approved_by' => auth()->id(),
        ];
    }

    private static function deductStock(Bill $bill): array
    {
        if (class_exists(StockDeductionService::class)) {
            return app(StockDeductionService::class)->deductForBill($bill);
        }

        return [];
    }

    private static function snapshotFiscalInvoice(Bill $bill, array $inventoryConsumption): void
    {
        if ($bill->fiscalSnapshot()->exists()) {
            throw ValidationException::withMessages([
                'bill' => 'This bill already has a fiscal snapshot.',
            ]);
        }

        $bill->loadMissing('orders.orderDetails.menu.category', 'orders.orderDetails.loyaltyRedeemedBy', 'lockedBy', 'payments');
        $business = app(BusinessConfigurationService::class)->details();
        $vatRate = app(VatCalculatorService::class)->vatRate();
        $items = $bill->orders
            ->flatMap->orderDetails
            ->flatMap(function ($detail) use ($vatRate, $inventoryConsumption) {
                $quantity = (float) $detail->quantity;
                $unitPrice = (float) ($detail->unit_price ?? $detail->menu?->price ?? 0);
                $rewardQuantity = min((float) $detail->loyalty_reward_quantity, $quantity);
                $paidQuantity = $quantity - $rewardQuantity;
                $categoryName = $detail->menu?->category->firstWhere('loyalty_eligible', true)?->name
                    ?? $detail->menu?->category->pluck('name')->sort()->first();
                $fullConsumption = collect($inventoryConsumption[$detail->id] ?? []);
                $paidConsumption = $fullConsumption->map(function (array $stock) use ($paidQuantity, $quantity) {
                    $stock['quantity'] = round((float) $stock['quantity'] * $paidQuantity / $quantity, 3);
                    return $stock;
                })->filter(fn (array $stock) => $stock['quantity'] > 0)->values();
                $rewardConsumption = $fullConsumption->map(function (array $stock, int $index) use ($paidConsumption) {
                    $stock['quantity'] = round((float) $stock['quantity'] - (float) data_get($paidConsumption->get($index), 'quantity', 0), 3);
                    return $stock;
                })->filter(fn (array $stock) => $stock['quantity'] > 0)->values()->all();

                $common = [
                    'source_order_detail_id' => $detail->id,
                    'item_name' => $detail->menu?->name ?? 'Deleted menu item',
                    'category_name' => $categoryName,
                    'unit_price' => round($unitPrice, 2),
                    'tax_category' => 'standard',
                    'vat_rate' => $vatRate,
                ];

                $items = [];
                if ($paidQuantity > 0) {
                    $items[] = array_merge($common, [
                        'inventory_consumption' => $paidConsumption->all() ?: null,
                        'quantity' => $paidQuantity,
                        'line_total' => round($paidQuantity * $unitPrice, 2),
                    ]);
                }

                if ($rewardQuantity > 0) {
                    $items[] = array_merge($common, [
                        'inventory_consumption' => $rewardConsumption ?: null,
                        'quantity' => $rewardQuantity,
                        'unit_price' => 0,
                        'original_unit_price' => round((float) ($detail->loyalty_original_unit_price ?? $unitPrice), 2),
                        'pricing_reason' => 'physical_stamp_card',
                        'approved_by_name' => $detail->loyaltyRedeemedBy?->name ?? 'System',
                        'approved_at' => $detail->loyalty_redeemed_at,
                        'line_total' => 0,
                    ]);
                }

                return $items;
            })
            ->values()
            ->all();

        $itemTotal = round((float) collect($items)->sum('line_total'), 2);

        if ($items === [] || abs($itemTotal - (float) $bill->bill_amount) > 0.001) {
            throw ValidationException::withMessages([
                'bill' => 'Invoice item totals do not match the bill subtotal.',
            ]);
        }

        $snapshot = [
            'bill_id' => $bill->id,
            'invoice_no' => $bill->invoice_no,
            'fiscal_year' => $bill->fiscal_year,
            'invoice_at' => $bill->locked_at,
            'seller_name' => $business['name'],
            'seller_address' => $business['address'],
            'seller_phone' => $business['phone'],
            'seller_email' => $business['email'],
            'seller_tax_registration' => $business['tax_registration'],
            'currency_symbol' => $business['currency_symbol'],
            'buyer_name' => $bill->buyer_name,
            'buyer_pan' => $bill->buyer_pan,
            'buyer_address' => $bill->buyer_address,
            'subtotal' => $bill->bill_amount,
            'discount' => $bill->discount,
            'service_charge' => $bill->service_charge_amount,
            'taxable_sales' => $bill->taxable_amount,
            'tax_exempted_sales' => 0,
            'vat_rate' => $vatRate,
            'vat' => $bill->vat_amount,
            'total_sales' => $bill->grand_total,
            'payment_method' => $bill->payment_method,
            'payment_breakdown' => $bill->payments->map(fn ($payment) => [
                'method' => $payment->payment_method,
                'amount' => $payment->amount,
                'reference_no' => $payment->reference_no,
            ])->values()->all() ?: null,
            'operator_id' => $bill->locked_by,
            'operator_name' => $bill->lockedBy?->name ?? auth()->user()?->name ?? 'System',
        ];

        $snapshot['document_hash'] = hash('sha256', json_encode(
            ['invoice' => $snapshot, 'items' => $items],
            JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));

        $fiscalSnapshot = FiscalInvoiceSnapshot::create($snapshot);
        $fiscalSnapshot->items()->createMany($items);
        app(CbmsService::class)->queue($fiscalSnapshot);
    }

    private static function addOrderDetail(
        $orderDetails,
        string $itemName,
        float $quantity,
        float $price,
        float $total,
        bool $loyaltyReward = false,
        ?float $originalPrice = null
    ): void
    {
        if ($orderDetails->has($itemName)) {
            $current = $orderDetails->get($itemName);
            $current['quantity'] += $quantity;
            $current['total'] += $total;
            $orderDetails->put($itemName, $current);

            return;
        }

        $orderDetails->put($itemName, [
            'quantity' => $quantity,
            'price' => $price,
            'total' => $total,
            'loyalty_reward' => $loyaltyReward,
            'original_price' => $originalPrice,
        ]);
    }

    private static function legacyDiscountData($discount): array
    {
        return [
            'discount_type' => (float) $discount > 0 ? 'amount' : null,
            'discount_value' => (float) $discount,
        ];
    }

    private static function discountPayload(float $total, array $data): array
    {
        $type = $data['discount_type'] ?? null;
        $value = (float) ($data['discount_value'] ?? 0);

        if (!$type || $value <= 0) {
            return [
                'discount' => 0,
                'discount_type' => null,
                'discount_value' => 0,
            ];
        }

        if (!in_array($type, ['amount', 'percentage'], true)) {
            throw ValidationException::withMessages([
                'discount_type' => 'Discount type must be amount or percentage.',
            ]);
        }

        if ($type === 'percentage' && $value > 100) {
            throw ValidationException::withMessages([
                'discount_value' => 'Percentage discount cannot be greater than 100.',
            ]);
        }

        $discount = $type === 'percentage'
            ? round($total * $value / 100, 2)
            : round($value, 2);

        if ($discount > $total) {
            throw ValidationException::withMessages([
                'discount_value' => 'Discount cannot be greater than the bill subtotal.',
            ]);
        }

        return [
            'discount' => $discount,
            'discount_type' => $type,
            'discount_value' => $value,
        ];
    }
}

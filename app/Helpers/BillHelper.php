<?php


namespace App\Helpers;

use App\Modules\Inventory\Services\StockDeductionService;
use App\Enums\OrderType;
use App\Models\Bill;
use App\Models\BillOrder;
use App\Models\Order;
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

    public static function finalizeBill(int $billId, string $paymentMethod, array $buyerData = [], array $discountData = [], bool $closeBill = false): Bill
    {
        return DB::transaction(function () use ($billId, $paymentMethod, $buyerData, $discountData, $closeBill) {
            $bill = Bill::with('orders')->lockForUpdate()->findOrFail($billId);

            if ($bill->isLocked()) {
                throw ValidationException::withMessages([
                    'bill' => 'This bill has already been finalized and cannot be edited.',
                ]);
            }

            $total = (float) $bill->orders->sum('total');
            $discountPayload = self::discountPayload($total, $discountData);
            $tax = app(VatCalculatorService::class)->calculate($total, (float) $discountPayload['discount']);
            $buyer = self::buyerPayload($buyerData);
            $credit = self::creditPayload($paymentMethod, $buyerData);
            $discountApproval = self::discountApprovalPayload((float) $discountPayload['discount'], $buyerData);

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
            self::deductStock($bill);

            return $bill;
        });
    }

    public static function getBillOrders($billId)
    {
        $billDetails = Bill::where('id', $billId)
            ->with('orders')
            ->with('orders.orderDetails')
            ->with('orders.orderDetails.menu')
            ->with('table')
            ->first();


        $orderDetails = collect([]);

        foreach ($billDetails->orders as $order) {
            foreach ($order->orderDetails as $orderDetail) {
                $itemName = $orderDetail->menu?->name ?? 'Deleted menu item';
                $quantity = $orderDetail->quantity;
                $price = (float) ($orderDetail->unit_price ?? $orderDetail->menu?->price ?? 0);

                if ($orderDetails->has($itemName)) {
                    // Retrieve current values
                    $currentDetails = $orderDetails->get($itemName);

                    // Update values
                    $currentDetails['quantity'] += $quantity;
                    $currentDetails['total'] += $quantity * $price;

                    // Put updated values back
                    $orderDetails->put($itemName, $currentDetails);
                } else {
                    $orderDetails->put($itemName, [
                        'quantity' => $quantity,
                        'price' => $price,
                        'total' => $quantity * $price
                    ]);
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

    private static function deductStock(Bill $bill): void
    {
        if (class_exists(StockDeductionService::class)) {
            app(StockDeductionService::class)->deductForBill($bill);
        }
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

<?php

namespace App\Modules\Inventory\Services;

use App\Models\Bill;
use App\Modules\Inventory\Models\MenuItemStockMapping;
use App\Modules\Inventory\Models\StockItem;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Support\Facades\DB;

class StockDeductionService
{
    public function deductForBill(Bill $bill): array
    {
        $bill->loadMissing('billOrders.order.orderDetails');
        $consumption = [];

        DB::transaction(function () use ($bill, &$consumption) {
            foreach ($bill->billOrders()->whereNull('stock_deducted_at')->with('order.orderDetails')->get() as $billOrder) {
                if (!$billOrder->order) {
                    continue;
                }

                foreach ($billOrder->order->orderDetails as $orderDetail) {
                    $mappings = MenuItemStockMapping::where('menu_item_id', $orderDetail->menu_id)
                        ->where('active', true)
                        ->with('stockItem')
                        ->get();

                    foreach ($mappings as $mapping) {
                        if (!$mapping->stockItem || !$mapping->stockItem->active || !$mapping->stockItem->auto_deduct) {
                            continue;
                        }

                        $quantity = (float) $mapping->quantity_per_sale * (float) $orderDetail->quantity;
                        $stockItem = StockItem::whereKey($mapping->stock_item_id)->lockForUpdate()->first();

                        if (!$stockItem) {
                            continue;
                        }

                        $newQuantity = (float) $stockItem->current_quantity - $quantity;

                        if ($newQuantity < 0) {
                            throw \Illuminate\Validation\ValidationException::withMessages([
                                'stock' => "Insufficient stock for {$stockItem->name}.",
                            ]);
                        }

                        $stockItem->current_quantity = $newQuantity;
                        $stockItem->save();

                        StockMovement::create([
                            'stock_item_id' => $stockItem->id,
                            'movement_type' => 'sale_auto',
                            'quantity' => $quantity,
                            'reference_type' => 'bill_order',
                            'reference_id' => $billOrder->id,
                            'reason' => 'sale_auto',
                            'running_balance_after' => $newQuantity,
                            'notes' => 'Auto deduction for bill ' . ($bill->invoice_no ?: $bill->bill_id),
                            'created_by' => auth()->id(),
                        ]);

                        $consumption[$orderDetail->id][] = [
                            'stock_item_id' => $stockItem->id,
                            'stock_item_name' => $stockItem->name,
                            'unit' => $stockItem->unit,
                            'quantity' => round($quantity, 3),
                        ];
                    }
                }

                $billOrder->update(['stock_deducted_at' => now()]);
            }
        });

        return $consumption;
    }
}

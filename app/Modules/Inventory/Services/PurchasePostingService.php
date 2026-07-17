<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\PurchaseInvoice;
use App\Modules\Inventory\Models\StockItem;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\SupplierPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchasePostingService
{
    public function post(PurchaseInvoice $invoice): PurchaseInvoice
    {
        if ($invoice->status === 'void') {
            throw ValidationException::withMessages(['invoice' => 'Voided purchase invoices cannot be posted.']);
        }

        if ($invoice->status === 'posted') {
            return $invoice;
        }

        return DB::transaction(function () use ($invoice) {
            $invoice->load('items');

            if ($invoice->items->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'Add at least one purchase item before posting.']);
            }

            foreach ($invoice->items as $item) {
                $stockItem = StockItem::whereKey($item->stock_item_id)->lockForUpdate()->firstOrFail();
                $oldQty = (float) $stockItem->current_quantity;
                $incomingQty = (float) $item->quantity;
                $newQty = $oldQty + $incomingQty;
                $unitPrice = (float) $item->unit_price;
                $oldAverage = (float) $stockItem->average_unit_cost;
                $newAverage = $newQty > 0
                    ? (($oldQty * $oldAverage) + ($incomingQty * $unitPrice)) / $newQty
                    : $unitPrice;

                $stockItem->update([
                    'current_quantity' => $newQty,
                    'average_unit_cost' => round($newAverage, 2),
                    'last_purchase_cost' => $unitPrice,
                    'default_supplier_id' => $stockItem->default_supplier_id ?: $invoice->supplier_id,
                ]);

                StockMovement::create([
                    'stock_item_id' => $stockItem->id,
                    'movement_type' => 'in',
                    'quantity' => $incomingQty,
                    'unit_cost' => $unitPrice,
                    'reference_type' => 'purchase_invoice',
                    'reference_id' => $invoice->id,
                    'reason' => 'purchase',
                    'running_balance_after' => $newQty,
                    'notes' => 'Purchase invoice ' . $invoice->invoice_no,
                    'created_by' => auth()->id(),
                ]);
            }

            $initialPaid = min((float) $invoice->paid_amount, (float) $invoice->total_amount);
            if ($initialPaid > 0 && $invoice->payment_method && !$invoice->payments()->exists()) {
                SupplierPayment::create([
                    'supplier_id' => $invoice->supplier_id,
                    'purchase_invoice_id' => $invoice->id,
                    'payment_date' => $invoice->bill_date,
                    'amount' => $initialPaid,
                    'payment_method' => $invoice->payment_method,
                    'notes' => 'Initial payment for invoice ' . $invoice->invoice_no,
                    'created_by' => auth()->id(),
                ]);
            }

            $invoice->status = 'posted';
            $invoice->posted_at = now();
            $invoice->posted_by = auth()->id();
            $invoice->save();

            $this->refreshPaymentStatus($invoice);

            return $invoice->fresh('supplier', 'items.stockItem', 'payments');
        });
    }

    public function void(PurchaseInvoice $invoice, string $reason): PurchaseInvoice
    {
        if ($invoice->status !== 'posted') {
            throw ValidationException::withMessages(['invoice' => 'Only posted purchase invoices can be voided.']);
        }

        if (!auth()->user()?->canVoidPurchase()) {
            abort(403, 'Only Admin or Owner can void posted purchase invoices.');
        }

        if ($invoice->payments()->exists()) {
            throw ValidationException::withMessages([
                'invoice' => 'Cannot void a purchase invoice that has recorded payments.',
            ]);
        }

        return DB::transaction(function () use ($invoice, $reason) {
            $invoice->load('items');

            foreach ($invoice->items as $item) {
                $stockItem = StockItem::whereKey($item->stock_item_id)->lockForUpdate()->firstOrFail();
                $newQty = (float) $stockItem->current_quantity - (float) $item->quantity;

                if ($newQty < 0) {
                    throw ValidationException::withMessages([
                        'invoice' => "Cannot void invoice because {$stockItem->name} stock would become negative.",
                    ]);
                }

                $stockItem->update(['current_quantity' => $newQty]);

                StockMovement::create([
                    'stock_item_id' => $stockItem->id,
                    'movement_type' => 'out',
                    'quantity' => $item->quantity,
                    'unit_cost' => $item->unit_price,
                    'reference_type' => 'purchase_void',
                    'reference_id' => $invoice->id,
                    'reason' => 'purchase_void',
                    'running_balance_after' => $newQty,
                    'notes' => $reason,
                    'created_by' => auth()->id(),
                ]);
            }

            $invoice->update([
                'status' => 'void',
                'voided_at' => now(),
                'voided_by' => auth()->id(),
                'void_reason' => $reason,
            ]);

            return $invoice->fresh('supplier', 'items.stockItem', 'payments');
        });
    }

    public function refreshPaymentStatus(PurchaseInvoice $invoice): void
    {
        $paid = (float) $invoice->payments()->sum('amount');
        $total = (float) $invoice->total_amount;
        $balance = max($total - $paid, 0);

        $invoice->forceFill([
            'paid_amount' => $paid,
            'balance_amount' => $balance,
            'payment_status' => $balance <= 0.009 ? 'paid' : ($paid > 0 ? 'partial' : 'pending'),
        ])->save();
    }
}

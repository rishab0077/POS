<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Models\FiscalCreditNoteItem;
use App\Modules\Inventory\Models\StockItem;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Requests\StoreStockMovementRequest;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockMovementController extends Controller
{
    public function index()
    {
        $movements = StockMovement::with('stockItem', 'createdBy')
            ->latest()
            ->paginate(50);
        $returnedItems = FiscalCreditNoteItem::with('creditNote.snapshot', 'inventoryRestoredBy')
            ->latest()
            ->limit(50)
            ->get();

        return view('modules.inventory.stock_movements.index', compact('movements', 'returnedItems'));
    }

    public function create()
    {
        $stockItems = StockItem::where('active', true)->orderBy('name')->get();

        return view('modules.inventory.stock_movements.create', compact('stockItems'));
    }

    public function store(StoreStockMovementRequest $request, AuditLogger $audit)
    {
        $movement = DB::transaction(function () use ($request) {
            $stockItem = StockItem::whereKey($request->stock_item_id)->lockForUpdate()->firstOrFail();
            $quantity = (float) $request->quantity;

            if ($request->movement_type === 'out' && (float) $stockItem->current_quantity < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => 'Manual stock out cannot exceed current quantity.',
                ]);
            }

            if ($request->movement_type === 'in') {
                $stockItem->current_quantity = (float) $stockItem->current_quantity + $quantity;
            } elseif ($request->movement_type === 'out') {
                $stockItem->current_quantity = (float) $stockItem->current_quantity - $quantity;
            } else {
                $stockItem->current_quantity = $quantity;
            }

            $stockItem->save();

            return StockMovement::create([
                'stock_item_id' => $stockItem->id,
                'movement_type' => $request->movement_type,
                'quantity' => $quantity,
                'unit_cost' => $request->unit_cost,
                'reason' => $request->reason,
                'running_balance_after' => $stockItem->current_quantity,
                'notes' => $request->notes,
                'created_by' => auth()->id(),
            ]);
        });

        $audit->record('manual_stock_movement_created', 'inventory', [
            'subject' => $movement,
            'after' => $movement->attributesToArray(),
            'metadata' => ['summary' => "Manual stock movement #{$movement->id} created."],
        ]);

        return redirect()->route('inventory.stock-movements.index')->with('success', 'Stock movement recorded.');
    }

    public function restoreReturn(FiscalCreditNoteItem $fiscalCreditNoteItem, AuditLogger $audit)
    {
        $movements = DB::transaction(function () use ($fiscalCreditNoteItem) {
            $item = FiscalCreditNoteItem::with('creditNote')->lockForUpdate()->findOrFail($fiscalCreditNoteItem->id);

            if ($item->inventory_restored_at) {
                throw ValidationException::withMessages(['inventory' => 'This returned item has already been restored.']);
            }

            $quantities = collect($item->inventory_restore_quantities ?? [])->sortBy('stock_item_id');

            if ($quantities->isEmpty()) {
                throw ValidationException::withMessages([
                    'inventory' => 'No restorable sale-deduction snapshot exists for this returned item.',
                ]);
            }

            $movements = $quantities->map(function ($restore) use ($item) {
                $stockItem = StockItem::whereKey($restore['stock_item_id'])->lockForUpdate()->first();

                if (!$stockItem) {
                    throw ValidationException::withMessages([
                        'inventory' => "Stock item {$restore['stock_item_name']} no longer exists.",
                    ]);
                }

                $quantity = round((float) $restore['quantity'], 3);
                $stockItem->current_quantity = round((float) $stockItem->current_quantity + $quantity, 3);
                $stockItem->save();

                return StockMovement::create([
                    'stock_item_id' => $stockItem->id,
                    'movement_type' => 'in',
                    'quantity' => $quantity,
                    'reference_type' => 'fiscal_credit_note_item',
                    'reference_id' => $item->id,
                    'reason' => 'return_restore',
                    'running_balance_after' => $stockItem->current_quantity,
                    'notes' => "Reusable return restored from {$item->creditNote->credit_note_no}",
                    'created_by' => auth()->id(),
                ]);
            });

            $item->update([
                'inventory_restored_at' => now(),
                'inventory_restored_by' => auth()->id(),
            ]);

            return $movements;
        });

        $audit->record('returned_item_inventory_restored', 'inventory', [
            'subject' => $fiscalCreditNoteItem,
            'after' => ['stock_movement_ids' => $movements->pluck('id')->all()],
            'metadata' => ['summary' => "Returned item #{$fiscalCreditNoteItem->id} restored to inventory."],
        ]);

        return redirect()->route('inventory.stock-movements.index')->with('success', 'Reusable returned stock restored.');
    }
}

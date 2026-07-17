<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
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

        return view('modules.inventory.stock_movements.index', compact('movements'));
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
}

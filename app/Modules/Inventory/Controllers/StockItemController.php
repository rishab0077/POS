<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\StockCategory;
use App\Modules\Inventory\Models\StockItem;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Requests\StoreStockItemRequest;
use App\Services\AuditLogger;
use Illuminate\Support\Str;

class StockItemController extends Controller
{
    public function index()
    {
        $stockItems = StockItem::with('category', 'defaultSupplier')->orderBy('name')->paginate(25);

        return view('modules.inventory.stock_items.index', compact('stockItems'));
    }

    public function create()
    {
        $stockItem = new StockItem([
            'current_quantity' => 0,
            'low_stock_threshold' => 0,
            'active' => true,
        ]);
        $categories = StockCategory::where('active', true)->orderBy('name')->get();
        $suppliers = Supplier::where('active', true)->orderBy('name')->get();

        return view('modules.inventory.stock_items.create', compact('stockItem', 'categories', 'suppliers'));
    }

    public function store(StoreStockItemRequest $request, AuditLogger $audit)
    {
        $stockItem = StockItem::create($this->payload($request));

        $audit->record('stock_item_created', 'inventory', [
            'subject' => $stockItem,
            'after' => $stockItem->attributesToArray(),
            'metadata' => ['summary' => "Stock item {$stockItem->name} created."],
        ]);

        return redirect()->route('inventory.stock-items.index')->with('success', 'Stock item created.');
    }

    public function edit(StockItem $stockItem)
    {
        $categories = StockCategory::where('active', true)->orderBy('name')->get();
        $suppliers = Supplier::where('active', true)->orderBy('name')->get();

        return view('modules.inventory.stock_items.edit', compact('stockItem', 'categories', 'suppliers'));
    }

    public function update(StoreStockItemRequest $request, StockItem $stockItem, AuditLogger $audit)
    {
        $before = $stockItem->attributesToArray();
        $stockItem->update($this->payload($request));

        $audit->record('stock_item_updated', 'inventory', [
            'subject' => $stockItem,
            'before' => $before,
            'after' => $stockItem->fresh()->attributesToArray(),
            'metadata' => ['summary' => "Stock item {$stockItem->name} updated."],
        ]);

        return redirect()->route('inventory.stock-items.index')->with('success', 'Stock item updated.');
    }

    private function payload(StoreStockItemRequest $request): array
    {
        $validated = $request->validated();
        $autoGenerateSku = (bool) ($validated['sku_auto_generated'] ?? false);
        unset($validated['sku_auto_generated']);

        $payload = array_merge($validated, [
            'auto_deduct' => $request->boolean('auto_deduct'),
            'active' => $request->boolean('active', true),
            'current_quantity' => $request->input('current_quantity', 0),
            'average_unit_cost' => $request->input('average_unit_cost', 0),
            'last_purchase_cost' => $request->input('last_purchase_cost', 0),
        ]);

        if ($autoGenerateSku || trim((string) ($payload['sku'] ?? '')) === '') {
            $payload['sku'] = $this->generateSku(
                $payload['name'],
                $payload['unit'],
                $request->route('stock_item')?->id
            );
        }

        return $payload;
    }

    private function generateSku(string $name, string $unit, ?int $ignoreId = null): string
    {
        $namePart = substr(Str::upper(Str::slug($name, '')), 0, 12) ?: 'ITEM';
        $unitPart = substr(Str::upper(Str::slug($unit, '')), 0, 8) ?: 'UNIT';
        $base = $namePart . '-' . $unitPart;
        $counter = 1;

        do {
            $sku = $base . '-' . str_pad((string) $counter, 4, '0', STR_PAD_LEFT);
            $query = StockItem::where('sku', $sku);

            if ($ignoreId) {
                $query->where('id', '!=', $ignoreId);
            }

            $exists = $query->exists();
            $counter++;
        } while ($exists);

        return $sku;
    }
}

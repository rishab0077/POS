<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Modules\Inventory\Models\MenuItemStockMapping;
use App\Modules\Inventory\Models\StockItem;
use App\Modules\Inventory\Requests\StoreMenuItemStockMappingRequest;
use App\Services\AuditLogger;

class MenuItemStockMappingController extends Controller
{
    public function index()
    {
        $mappings = MenuItemStockMapping::with('menu', 'stockItem')
            ->latest()
            ->paginate(50);

        return view('modules.inventory.menu_mappings.index', compact('mappings'));
    }

    public function create()
    {
        $mapping = new MenuItemStockMapping(['active' => true]);

        return view('modules.inventory.menu_mappings.create', $this->formData($mapping));
    }

    public function store(StoreMenuItemStockMappingRequest $request, AuditLogger $audit)
    {
        $mapping = MenuItemStockMapping::create($this->payload($request));

        $audit->record('menu_item_stock_mapping_created', 'inventory', [
            'subject' => $mapping,
            'after' => $mapping->attributesToArray(),
            'metadata' => ['summary' => "Menu item stock mapping #{$mapping->id} created."],
        ]);

        return redirect()->route('inventory.menu-mappings.index')->with('success', 'Menu stock mapping created.');
    }

    public function edit(MenuItemStockMapping $menuMapping)
    {
        return view('modules.inventory.menu_mappings.edit', $this->formData($menuMapping));
    }

    public function update(StoreMenuItemStockMappingRequest $request, MenuItemStockMapping $menuMapping, AuditLogger $audit)
    {
        $before = $menuMapping->attributesToArray();
        $menuMapping->update($this->payload($request));

        $audit->record('menu_item_stock_mapping_updated', 'inventory', [
            'subject' => $menuMapping,
            'before' => $before,
            'after' => $menuMapping->fresh()->attributesToArray(),
            'metadata' => ["summary" => "Menu item stock mapping #{$menuMapping->id} updated."],
        ]);

        return redirect()->route('inventory.menu-mappings.index')->with('success', 'Menu stock mapping updated.');
    }

    public function destroy(MenuItemStockMapping $menuMapping, AuditLogger $audit)
    {
        $before = $menuMapping->attributesToArray();
        $menuMapping->delete();

        $audit->record('menu_item_stock_mapping_deleted', 'inventory', [
            'subject_type' => MenuItemStockMapping::class,
            'subject_id' => $menuMapping->id,
            'before' => $before,
            'metadata' => ['summary' => "Menu item stock mapping #{$menuMapping->id} deleted."],
        ]);

        return redirect()->route('inventory.menu-mappings.index')->with('danger', 'Menu stock mapping deleted.');
    }

    private function formData(MenuItemStockMapping $mapping): array
    {
        return [
            'mapping' => $mapping,
            'menus' => Menu::orderBy('name')->get(),
            'stockItems' => StockItem::where('active', true)->orderBy('name')->get(),
        ];
    }

    private function payload(StoreMenuItemStockMappingRequest $request): array
    {
        return array_merge($request->validated(), [
            'active' => $request->boolean('active', true),
        ]);
    }
}

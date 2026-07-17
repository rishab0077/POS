<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\StockItem;

class InventoryDashboardController extends Controller
{
    public function index()
    {
        $lowStockItems = StockItem::with('category')
            ->where('active', true)
            ->whereColumn('current_quantity', '<=', 'low_stock_threshold')
            ->orderBy('name')
            ->get();

        return view('modules.inventory.dashboard', compact('lowStockItems'));
    }
}

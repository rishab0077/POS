<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\PurchaseInvoice;
use App\Modules\Inventory\Models\StockItem;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Models\SupplierPayment;

class InventoryReportController extends Controller
{
    public function index()
    {
        $stockItems = StockItem::with('category', 'defaultSupplier')->orderBy('name')->get();
        $stockValue = $stockItems->sum(fn ($item) => (float) $item->current_quantity * (float) $item->average_unit_cost);

        $suppliers = Supplier::withSum(['purchaseInvoices as posted_purchase_total' => fn ($query) => $query->where('status', 'posted')], 'total_amount')
            ->withSum('payments as payment_total', 'amount')
            ->orderBy('name')
            ->get();

        $payables = PurchaseInvoice::with('supplier')
            ->where('status', 'posted')
            ->where('balance_amount', '>', 0)
            ->orderBy('due_date')
            ->orderBy('bill_date')
            ->get();

        $recentPurchases = PurchaseInvoice::with('supplier')
            ->latest('bill_date')
            ->limit(10)
            ->get();

        $recentPayments = SupplierPayment::with('supplier', 'purchaseInvoice')
            ->latest('payment_date')
            ->limit(10)
            ->get();

        return view('modules.inventory.reports.index', compact(
            'stockItems',
            'stockValue',
            'suppliers',
            'payables',
            'recentPurchases',
            'recentPayments'
        ));
    }
}

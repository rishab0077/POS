<?php

use App\Modules\Inventory\Controllers\InventoryDashboardController;
use App\Modules\Inventory\Controllers\InventoryReportController;
use App\Modules\Inventory\Controllers\MenuItemStockMappingController;
use App\Modules\Inventory\Controllers\PurchaseInvoiceController;
use App\Modules\Inventory\Controllers\StockItemController;
use App\Modules\Inventory\Controllers\StockMovementController;
use App\Modules\Inventory\Controllers\SupplierController;
use App\Modules\Inventory\Controllers\SupplierPaymentController;
use Illuminate\Support\Facades\Route;

Route::get('/', [InventoryDashboardController::class, 'index'])->name('dashboard');
Route::resource('suppliers', SupplierController::class)->except(['show', 'destroy']);
Route::post('purchase-invoices/{purchase_invoice}/post', [PurchaseInvoiceController::class, 'post'])->name('purchase-invoices.post');
Route::post('purchase-invoices/{purchase_invoice}/void', [PurchaseInvoiceController::class, 'void'])->name('purchase-invoices.void');
Route::resource('purchase-invoices', PurchaseInvoiceController::class);
Route::resource('supplier-payments', SupplierPaymentController::class)->only(['index', 'create', 'store']);
Route::get('reports', [InventoryReportController::class, 'index'])->name('reports.index');
Route::resource('stock-items', StockItemController::class)->except(['show', 'destroy']);
Route::resource('stock-movements', StockMovementController::class)->only(['index', 'create', 'store']);
Route::post('returned-items/{fiscalCreditNoteItem}/restore', [StockMovementController::class, 'restoreReturn'])
    ->name('returned-items.restore');
Route::resource('menu-mappings', MenuItemStockMappingController::class)->except(['show']);

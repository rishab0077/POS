<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\AuditEventController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CbmsSubmissionController;
use App\Http\Controllers\Admin\MenuController;
use App\Http\Controllers\Admin\ReservationController;
use App\Http\Controllers\Admin\TableController;
use App\Http\Controllers\Admin\TableLocationController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\PrintStationController;
use App\Http\Controllers\Admin\SystemStatusController;
use App\Http\Controllers\Billing\BillController;
use App\Http\Controllers\BillerController;
use App\Http\Controllers\Frontend\CategoryController as FrontendCategoryController;
use App\Http\Controllers\Frontend\MenuController as FrontendMenuController;
use App\Http\Controllers\Frontend\ReservationController as FrontendReservationController;
use App\Http\Controllers\Order\OrderController as OrderController;
use App\Http\Controllers\Frontend\WelcomeController;
use App\Http\Controllers\Kitchen\KOTController;
use App\Http\Controllers\Order\OrderSyncController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Restaurant\RestaurantController;
use App\Http\Controllers\POS\PosController;
use App\Http\Controllers\RedirectController;
use App\Http\Controllers\HealthController;

/**
 * -----------------------------------------------------------------------------------------------------------------------------
 * Routes for FrontEnd & Reservation
 * -----------------------------------------------------------------------------------------------------------------------------
 */
Route::get('/health', HealthController::class)->middleware('health.access')->name('health');
Route::middleware('public.access')->group(function () {
    Route::get('/', [WelcomeController::class, 'index']);
    Route::get('/categories', [FrontendCategoryController::class, 'index'])->name('categories.index');
    Route::get('/categories/{category}', [FrontendCategoryController::class, 'show'])->name('categories.show');
    Route::get('/menus', [FrontendMenuController::class, 'index'])->name('menus.index');

    Route::get('/reservation/step-one', [FrontendReservationController::class, 'stepOne'])->name('reservations.step.one');
    Route::post('/reservation/step-one', [FrontendReservationController::class, 'storeStepOne'])->name('reservations.store.step.one');
    Route::get('/reservation/step-two', [FrontendReservationController::class, 'stepTwo'])->name('reservations.step.two');
    Route::post('/reservation/step-two', [FrontendReservationController::class, 'storeStepTwo'])->name('reservations.store.step.two');
    Route::get('/thankyou', [WelcomeController::class, 'thankyou'])->name('thankyou');
});

/**
 * -----------------------------------------------------------------------------------------------------------------------------
 * Routes for Dashboard
 * -----------------------------------------------------------------------------------------------------------------------------
 */

Route::middleware(['private.access', 'auth'])->get('/dashboard', [RedirectController::class, 'dashboard'])->name('dashboard');


/**
 * -----------------------------------------------------------------------------------------------------------------------------
 * Routes for Admin
 * -----------------------------------------------------------------------------------------------------------------------------
 */

Route::middleware(['private.access', 'auth', 'admin'])->name('admin.')->prefix('admin')->group(function () {
    Route::get('/', [AdminController::class, 'index'])->name('index');
    Route::resource('/categories', CategoryController::class)->except('show');
    Route::post('categories/update-ranks', [CategoryController::class, 'updateRanks'])->name('categories.updateRanks');

    Route::resource('/menus', MenuController::class)->except('show');
    Route::resource('/tables', TableController::class)->except('show');
    Route::resource('/table-location', TableLocationController::class)->except('show');
    Route::resource('/reservations', ReservationController::class)->except('show');
    Route::resource('/users', UserController::class)->except('show');
    Route::post('/users/{user}/reset-mfa', [UserController::class, 'resetMfa'])->name('users.reset-mfa');
    Route::get('/print-stations', [PrintStationController::class, 'index'])->name('print-stations.index');
    Route::post('/print-stations', [PrintStationController::class, 'store'])->name('print-stations.store');
    Route::put('/print-stations/{printStation}', [PrintStationController::class, 'update'])->name('print-stations.update');
    Route::post('/print-stations/{printStation}/regenerate-token', [PrintStationController::class, 'regenerateToken'])->name('print-stations.regenerate-token');
    Route::post('/print-jobs/{printJob}/retry', [PrintStationController::class, 'retry'])->name('print-jobs.retry');
    Route::get('/cbms', [CbmsSubmissionController::class, 'index'])->name('cbms.index');
    Route::post('/cbms/{cbmsSubmission}/retry', [CbmsSubmissionController::class, 'retry'])->name('cbms.retry');
    Route::post('/cbms/{cbmsSubmission}/credit-note', [CbmsSubmissionController::class, 'issueCreditNote'])->name('cbms.credit-note.issue');
    Route::post('/cbms/credit-notes/{fiscalCreditNote}/retry', [CbmsSubmissionController::class, 'retryCreditNote'])->name('cbms.credit-note.retry');
    Route::get('/cbms/credit-notes/{fiscalCreditNote}/print', [CbmsSubmissionController::class, 'printCreditNote'])->name('cbms.credit-note.print');
    Route::get('/system/status', SystemStatusController::class)->name('system.status');
    Route::get('/audit-events', [AuditEventController::class, 'index'])->name('audit-events.index');
    Route::get('/audit-events/export', [AuditEventController::class, 'export'])
        ->middleware('throttle:audit-export')
        ->name('audit-events.export');

    Route::view('/bills', 'admin.bills.index')->name('bills.index');

    Route::delete('/bill/{id}', [BillController::class, 'destroy'])->name('bill.destroy');


    Route::get('/bills-by-date', [BillController::class, 'getBillsByDate'])->name('bills.by.date');


    Route::get('/bill/view/{id}', [BillController::class, 'viewBill'])->name('view.bill');

    Route::get('/bill/print/{id}', [BillController::class, 'StreamBillToBrowser'])->name('stream.bill');

    Route::get('/KOTs', [KOTController::class, 'displayKOTs'])->name('KOTs');

    /**
     * -----------------------------------------------------------------------------------------------------------------------------
     * Routes for Restaurant
     * -----------------------------------------------------------------------------------------------------------------------------
     */

    Route::prefix('restaurant')->name('restaurant.')->group(function () { // Nested prefix for cleaner routes
        Route::get('/config', [RestaurantController::class, 'showConfig'])->name('show.config');
        Route::post('/update-config', [RestaurantController::class, 'updateConfig'])->name('update.config');

        // New route for fetching module status (GET request)
        Route::get('/module-status', [RestaurantController::class, 'getModuleStatus'])->name('module.status');

        // Routes for module enabling/disabling via AJAX
        Route::post('/enable-waiter-module', [RestaurantController::class, 'enableWaiterModule'])->name('enable_waiter_module');
        Route::post('/disable-waiter-module', [RestaurantController::class, 'disableWaiterModule'])->name('disable_waiter_module');
        Route::post('/enable-kitchen-module', [RestaurantController::class, 'enableKitchenModule'])->name('enable_kitchen_module');
        Route::post('/disable-kitchen-module', [RestaurantController::class, 'disableKitchenModule'])->name('disable_kitchen_module');
    });
});

/**
 * -----------------------------------------------------------------------------------------------------------------------------
 * Routes for Biller
 * -----------------------------------------------------------------------------------------------------------------------------
 */

Route::middleware(['private.access', 'auth', 'biller'])->name('biller.')->prefix('biller')->group(function () {
    Route::get('/', [BillerController::class, 'index'])->name('index');
});


/**
 * -----------------------------------------------------------------------------------------------------------------------------
 * Routes for POS
 * -----------------------------------------------------------------------------------------------------------------------------
 */


Route::middleware(['private.access', 'auth', 'biller', 'ensure.pos.configured'])->name('pos.')->prefix('pos')->group(function () {
    Route::get('/select-table', [PosController::class, 'selectTable'])->name('tables');
    Route::get('/order', [PosController::class, 'index'])->name('main');
    Route::get('/bill/{id}/preview', [BillController::class, 'previewBill'])->name('bill.preview');
    Route::post('/table/submit-for-billing', [PosController::class, 'billTable'])->name('table.bill');
    Route::post('/table/settle', [PosController::class, 'settleTable'])->name('table.settle');
    Route::post('/table/transfer', [PosController::class, 'transferTable'])->name('table.transfer');
    Route::post('/order-details/{orderDetail}/loyalty', [PosController::class, 'updateLoyaltyReward'])->name('loyalty.update');
    Route::get('/table/orders/{tableId}', [PosController::class, 'tableOrders'])->name('table.orders');
});

/**
 * -------------------------------------------------s----------------------------------------------------------------------------
 * Routes for Order
 * -----------------------------------------------------------------------------------------------------------------------------
 */

Route::middleware(['private.access', 'auth'])->name('order.')->prefix('order')->group(function () {

    Route::post('/submit', [OrderController::class, 'submit'])->name('submit');

    Route::get('/KOT-view', [OrderController::class, 'KOTView'])->name('KOT.view');

    Route::post('/mark-as-served', [OrderController::class, 'markAsServed'])->name('mark.as.served');
    Route::post('/mark-as-prepared', [OrderController::class, 'markAsPrepared'])->name('mark.as.prepared');
    Route::post('/mark-as-closed', [OrderController::class, 'markAsClosed'])->name('mark.as.closed');
});

/**
 * -----------------------------------------------------------------------------------------------------------------------------
 * Routes for Sync
 * -----------------------------------------------------------------------------------------------------------------------------
 */

Route::middleware(['private.access', 'auth'])->name('sync.')->prefix('sync')->group(function () {

    Route::get('/check-pending-orders-updates', [OrderSyncController::class, 'syncPendingOrder'])->name('pending.orders');
    Route::get('/check-pickup-orders-updates', [OrderSyncController::class, 'syncPickUpOrder'])->name('pickup.orders');
});

require __DIR__ . '/auth.php';

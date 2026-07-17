<?php

use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Billing\CreditController;
use Illuminate\Support\Facades\Route;

/**
 * -----------------------------------------------------------------------------------------------------------------------------
 * Routes for Analytics
 * -----------------------------------------------------------------------------------------------------------------------------
 */
Route::middleware(['private.access', 'auth', 'biller'])->name('reporting.')->prefix('reporting')->group(function () {

    //Analytics Home Page

    Route::get('/', [AnalyticsController::class, 'index'])->name('index');
    Route::get('/daily-summary', [AnalyticsController::class, 'dailySummary'])->name('daily-summary');
    Route::get('/discounts', [AnalyticsController::class, 'discounts'])->name('discounts');
    Route::get('/credits', [CreditController::class, 'index'])->name('credits');
    Route::post('/credits/{bill}/payments', [CreditController::class, 'settle'])->name('credits.settle');

    //Sales By Item
    Route::get('/view/{report}', [AnalyticsController::class, 'view'])
        ->whereIn('report', ['sales-by-item', 'sales-by-category'])
        ->name('view');

    //Sales By Item Data
    Route::get('/sales-by-item-data', [AnalyticsController::class, 'salesByItemData'])->name('salesByItemData');

    //Sales By Category Data
    Route::get('/sales-by-category-data', [AnalyticsController::class, 'salesByCategoryData'])->name('salesByCategoryData');
});

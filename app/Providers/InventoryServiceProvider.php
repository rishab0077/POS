<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class InventoryServiceProvider extends ServiceProvider
{
    public function boot()
    {
        Route::middleware(['web', 'private.access', 'auth', 'inventory.manage'])
            ->prefix('inventory')
            ->name('inventory.')
            ->group(base_path('routes/inventory.php'));
    }
}

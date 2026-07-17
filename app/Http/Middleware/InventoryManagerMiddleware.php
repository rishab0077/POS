<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class InventoryManagerMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user() && $request->user()->canManageInventory()) {
            return $next($request);
        }

        abort(403, 'Unauthorized access to inventory.');
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class Admin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();



        if ($user->isBiller()) {

            $allowedRoutesForBiller = [
                'admin.bills.index',
                'admin.bills.by.date',
                'admin.view.bill',
                'admin.stream.bill'
            ];

            if (in_array($request->route()->getName(), $allowedRoutesForBiller, true)) {
                return $next($request);
            }
        }

        if ($user->canAdministerApplication()) {
            return $next($request);
        }

        // Redirect or respond as needed for non-waiter users
        abort(403, 'Unauthorized access.'); // You can customize the response as needed

    }
}

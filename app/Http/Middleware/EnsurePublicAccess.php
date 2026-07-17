<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsurePublicAccess
{
    public function handle(Request $request, Closure $next)
    {
        if (config('security.hosts.enforce_separation')) {
            abort_unless(in_array($request->getHost(), config('security.hosts.public', []), true), 404);
        }

        return $next($request);
    }
}

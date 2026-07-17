<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AuthenticatePrintService
{
    public function handle(Request $request, Closure $next)
    {
        if (config('security.hosts.enforce_separation') && config('security.hosts.print')) {
            if (!in_array($request->getHost(), config('security.hosts.print'), true)) {
                return response()->json(['message' => 'Invalid print service host.'], 404);
            }
        }

        if (!config('security.print_service.authentication_required')) {
            return $next($request);
        }

        $expectedId = (string) config('security.print_service.client_id');
        $expectedSecret = (string) config('security.print_service.client_secret');
        $providedId = (string) $request->header('CF-Access-Client-Id');
        $providedSecret = (string) $request->header('CF-Access-Client-Secret');

        if (
            $expectedId === ''
            || $expectedSecret === ''
            || !hash_equals($expectedId, $providedId)
            || !hash_equals($expectedSecret, $providedSecret)
        ) {
            return response()->json(['message' => 'Invalid print service credentials.'], 401);
        }

        return $next($request);
    }
}

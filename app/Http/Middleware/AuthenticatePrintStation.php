<?php

namespace App\Http\Middleware;

use App\Models\PrintStation;
use Closure;
use Illuminate\Http\Request;

class AuthenticatePrintStation
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['message' => 'Print station token is required.'], 401);
        }

        $station = PrintStation::enabled()
            ->where('token_hash', PrintStation::hashToken($token))
            ->first();

        if (!$station) {
            return response()->json(['message' => 'Invalid or disabled print station token.'], 401);
        }

        $request->attributes->set('print_station', $station);

        return $next($request);
    }
}

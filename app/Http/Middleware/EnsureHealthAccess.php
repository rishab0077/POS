<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\IpUtils;

class EnsureHealthAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('security.health.public_enabled')) {
            return $next($request);
        }

        $allowed = config('security.health.allowed_ips', []);

        abort_if(empty($allowed), 503, 'Health checks are restricted but no allowed IP ranges are configured.');
        abort_unless(IpUtils::checkIp($request->ip(), $allowed), 404);

        return $next($request);
    }
}

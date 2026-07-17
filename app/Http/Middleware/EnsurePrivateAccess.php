<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

class EnsurePrivateAccess
{
    public function handle(Request $request, Closure $next)
    {
        if (config('security.hosts.enforce_separation')) {
            $hosts = config('security.hosts.private', []);

            abort_unless(in_array($request->getHost(), $hosts, true), 404);
        }

        if (config('security.private_access.ip_restriction_enabled')) {
            $allowed = array_merge(
                config('security.private_access.allowed_ips', []),
                config('security.private_access.emergency_ips', []),
            );

            abort_if(empty($allowed), 503, 'Private access is enabled but no allowed IP ranges are configured.');
            abort_unless(IpUtils::checkIp($request->ip(), $allowed), 403, 'This network is not authorized to access the private POS.');
        }

        return $next($request);
    }
}

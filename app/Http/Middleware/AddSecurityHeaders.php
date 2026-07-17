<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!config('security.headers.enabled')) {
            return $response;
        }

        $this->setHeaderIfConfigured($response, 'X-Content-Type-Options', config('security.headers.x_content_type_options'));
        $this->setHeaderIfConfigured($response, 'X-Frame-Options', config('security.headers.x_frame_options'));
        $this->setHeaderIfConfigured($response, 'Referrer-Policy', config('security.headers.referrer_policy'));
        $this->setHeaderIfConfigured($response, 'Permissions-Policy', config('security.headers.permissions_policy'));
        $this->setHeaderIfConfigured($response, 'Cross-Origin-Opener-Policy', config('security.headers.cross_origin_opener_policy'));

        if (config('security.headers.csp_report_only_enabled')) {
            $this->setHeaderIfConfigured($response, 'Content-Security-Policy-Report-Only', config('security.headers.csp_report_only'));
        }

        if ($request->isSecure() && config('security.headers.hsts_enabled')) {
            $this->setHeaderIfConfigured($response, 'Strict-Transport-Security', config('security.headers.hsts_value'));
        }

        return $response;
    }

    private function setHeaderIfConfigured(Response $response, string $name, ?string $value): void
    {
        if (filled($value) && !$response->headers->has($name)) {
            $response->headers->set($name, $value);
        }
    }
}

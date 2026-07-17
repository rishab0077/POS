<?php

namespace App\Http\Middleware;

use App\Services\RecentAuthenticationService;
use Closure;
use Illuminate\Http\Request;

class EnsureRecentAuthentication
{
    public function __construct(private RecentAuthenticationService $recentAuthentication)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        if ($this->recentAuthentication->isRecent($request)) {
            return $next($request);
        }

        $returnTo = $request->isMethod('GET') ? $request->fullUrl() : url()->previous();

        if (!$this->isLocalUrl($request, $returnTo)) {
            $returnTo = route('dashboard');
        }

        $request->session()->put('auth.step_up_return_to', $returnTo);
        $stepUpUrl = route('password.confirm');

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'message' => 'Confirm your password and MFA before performing this sensitive action.',
                'step_up_url' => $stepUpUrl,
            ], 423);
        }

        return redirect()->route('password.confirm');
    }

    private function isLocalUrl(Request $request, string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return $host === null || $host === $request->getHost();
    }
}

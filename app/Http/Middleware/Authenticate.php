<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Support\Facades\Auth;

class Authenticate extends Middleware
{
    public function handle($request, Closure $next, ...$guards)
    {
        $this->authenticate($request, $guards);

        if (!$request->user()?->is_active) {
            Auth::guard($guards[0] ?? null)->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            if ($request->expectsJson()) {
                return response()->json(['message' => 'This account is inactive.'], 403);
            }

            return redirect()->route('login')
                ->withErrors(['username' => 'This account is inactive.']);
        }

        if (
            $request->user()->mustCompleteMfa()
            && !(bool) $request->session()->get('auth.mfa_passed', false)
            && !$request->routeIs('mfa.*')
            && !$request->routeIs('logout')
        ) {
            return $request->user()->hasMfaEnabled()
                ? redirect()->guest(route('mfa.challenge'))
                : redirect()->guest(route('mfa.setup'));
        }

        return $next($request);
    }

    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    protected function redirectTo($request)
    {
        if (! $request->expectsJson()) {
            return route('login');
        }
    }
}

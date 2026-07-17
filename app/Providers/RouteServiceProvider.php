<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * This is used by Laravel authentication to redirect users after login.
     *
     * @var string
     */
    public const HOME = '/dashboard';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::prefix('api')
                ->middleware('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));

            Route::middleware('web')
                ->group(base_path('routes/kitchen.php'));

            Route::middleware('web')
                ->group(base_path('routes/waiter.php'));

            Route::middleware('web')
                ->group(base_path('routes/analytics.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute((int) config('security.rate_limits.api_per_minute', 120))
                ->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('print-station', function (Request $request) {
            return Limit::perMinute((int) config('security.rate_limits.print_station_per_minute', 120))
                ->by($request->bearerToken() ? sha1($request->bearerToken()) : $request->ip());
        });

        RateLimiter::for('broadcast-auth', function (Request $request) {
            return Limit::perMinute((int) config('security.rate_limits.broadcast_auth_per_minute', 60))
                ->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('audit-export', function (Request $request) {
            return Limit::perMinute((int) config('security.rate_limits.audit_export_per_minute', 6))
                ->by($request->user()?->id ?: $request->ip());
        });
    }
}

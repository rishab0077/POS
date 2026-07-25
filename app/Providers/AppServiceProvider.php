<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Blade;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //  
        if (
            $this->app->environment('local')
            && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)
        ) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Password::defaults(fn () => Password::min(12)->letters()->numbers());

        Blade::directive('target', function () {
            // Read the value from your .env file, default to '_blank'
            $target = env('LINK_TARGET', '_blank');
            return "<?php echo 'target=\"$target\"'; ?>";
        });
    }
}

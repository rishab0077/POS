<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        putenv('APP_ENV=testing');
        putenv('APP_CONFIG_CACHE=bootstrap/cache/testing-config.php');
        $_ENV['APP_ENV'] = 'testing';
        $_ENV['APP_CONFIG_CACHE'] = 'bootstrap/cache/testing-config.php';
        $_SERVER['APP_ENV'] = 'testing';
        $_SERVER['APP_CONFIG_CACHE'] = 'bootstrap/cache/testing-config.php';

        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}

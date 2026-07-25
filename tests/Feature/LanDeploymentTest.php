<?php

namespace Tests\Feature;

use Tests\TestCase;

class LanDeploymentTest extends TestCase
{
    public function test_lan_binding_is_explicit_and_realtime_uses_the_request_origin(): void
    {
        $compose = file_get_contents(base_path('docker-compose.yml'));
        $javascript = file_get_contents(resource_path('js/kitchen.js'));
        $layout = file_get_contents(resource_path('views/layouts/kitchen.blade.php'));
        $dockerEnvironment = file_get_contents(base_path('.env.docker.example'));
        $productionEnvironment = file_get_contents(base_path('.env.production.example'));

        $this->assertStringContainsString('${APP_BIND_ADDRESS:-127.0.0.1}', $compose);
        $this->assertStringContainsString('meta[name="realtime-key"]', $javascript);
        $this->assertStringContainsString('window.location.hostname', $javascript);
        $this->assertStringNotContainsString('import.meta.env.VITE_PUSHER', $javascript);
        $this->assertStringContainsString('name="realtime-key"', $layout);
        $this->assertStringContainsString('PUSHER_HOST=soketi', $dockerEnvironment);
        $this->assertStringContainsString('PUSHER_HOST=soketi', $productionEnvironment);
        $this->assertStringNotContainsString('VITE_PUSHER', $dockerEnvironment);
        $this->assertStringNotContainsString('VITE_PUSHER', $productionEnvironment);
    }
}

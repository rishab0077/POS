<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use Database\Seeders\RestaurantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CbmsPreflightTest extends TestCase
{
    use RefreshDatabase;

    public function test_preflight_fails_closed_until_live_cbms_prerequisites_are_configured(): void
    {
        $this->seed(RestaurantSeeder::class);

        $this->artisan('cbms:preflight')
            ->expectsOutputToContain('[FAIL]')
            ->assertExitCode(1);

        Restaurant::firstOrFail()->update([
            'name' => 'Example Restaurant Pvt. Ltd.',
            'address' => 'Kathmandu, Nepal',
            'GST' => '123456789',
        ]);
        config()->set('services.cbms', [
            'enabled' => false,
            'acceptance_mode' => false,
            'url' => 'https://cbapi.ird.gov.np',
            'username' => 'taxpayer',
            'password' => 'secret',
            'timeout' => 15,
        ]);
        config()->set('queue.default', 'database');
        config()->set('app.timezone', 'Asia/Kathmandu');
        config()->set('app.debug', false);
        Cache::forever('operations.scheduler_last_seen', now()->toIso8601String());

        $this->artisan('cbms:preflight')
            ->expectsOutput('[PASS] Seller PAN contains exactly nine digits')
            ->expectsOutput('[PASS] CBMS endpoint is the official HTTPS host')
            ->expectsOutput('[PASS] Database migrations are current')
            ->expectsOutput('[PASS] Scheduler heartbeat is current')
            ->expectsOutput('[PASS] Application and database clocks agree')
            ->expectsOutput('[PASS] CBMS outbox has no unresolved records')
            ->expectsOutput('[INFO] CBMS delivery is disabled')
            ->expectsOutput('[INFO] CBMS acceptance mode is disabled')
            ->assertExitCode(0);
    }
}

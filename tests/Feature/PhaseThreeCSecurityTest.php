<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\PrintStation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class PhaseThreeCSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_headers_are_added_without_hsts_on_plain_http(): void
    {
        $this->get('/health')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeader('Content-Security-Policy-Report-Only')
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_is_added_for_secure_requests_when_enabled(): void
    {
        Config::set('security.headers.hsts_enabled', true);

        $this->get('https://localhost/health')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_health_check_public_default_preserves_deployment_compatibility(): void
    {
        Config::set('security.health.public_enabled', true);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->getJson('/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }

    public function test_health_check_can_be_restricted_to_allowed_ips(): void
    {
        Config::set('security.health.public_enabled', false);
        Config::set('security.health.allowed_ips', ['127.0.0.1', '10.20.30.0/24']);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->getJson('/health')
            ->assertNotFound();

        $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])
            ->getJson('/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }

    public function test_default_security_config_does_not_lock_out_pos_or_printing(): void
    {
        $this->assertFalse(config('security.hosts.enforce_separation'));
        $this->assertFalse(config('security.private_access.ip_restriction_enabled'));
        $this->assertTrue(config('security.health.public_enabled'));
        $this->assertFalse(config('security.print_service.authentication_required'));
        $this->assertSame(120, config('security.rate_limits.print_station_per_minute'));
    }

    public function test_print_station_rate_limit_is_configurable_without_service_headers(): void
    {
        Config::set('security.print_service.authentication_required', false);
        Config::set('security.rate_limits.print_station_per_minute', 1);
        $token = 'ps_rate_limit_token';

        PrintStation::create([
            'name' => 'Reception',
            'token_hash' => PrintStation::hashToken($token),
            'enabled' => true,
            'printer_map' => ['counter' => 'COUNTER'],
        ]);

        $this->withToken($token)
            ->postJson('/api/print-station/heartbeat')
            ->assertOk();

        $this->withToken($token)
            ->postJson('/api/print-station/heartbeat')
            ->assertTooManyRequests();
    }

    public function test_audit_export_is_protected_and_rate_limited(): void
    {
        Config::set('security.rate_limits.audit_export_per_minute', 1);

        $this->get(route('admin.audit-events.export'))->assertRedirect();

        $biller = User::factory()->create(['category_id' => UserRole::Biller]);
        $this->actingAs($biller)
            ->withSession(['auth.mfa_passed' => true])
            ->get(route('admin.audit-events.export'))
            ->assertForbidden();

        $admin = User::factory()->create(['category_id' => UserRole::Admin]);
        $response = $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->get(route('admin.audit-events.export'))
            ->assertOk();

        $this->assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'));

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->get(route('admin.audit-events.export'))
            ->assertTooManyRequests();
    }

}

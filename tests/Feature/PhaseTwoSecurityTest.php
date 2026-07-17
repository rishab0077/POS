<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\PrintStation;
use App\Models\User;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PhaseTwoSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_mfa_can_be_disabled_for_the_offline_local_package(): void
    {
        Config::set('security.mfa.enabled', false);
        $admin = User::factory()->create(['category_id' => UserRole::Admin]);

        $this->post('/login', [
            'username' => $admin->username,
            'password' => 'password',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($admin);
        $this->get('/dashboard')->assertRedirect('/admin');
        $this->get('/admin')->assertStatus(200);
    }

    public function test_privileged_user_is_forced_to_enroll_mfa_after_password_login(): void
    {
        $admin = User::factory()->create(['category_id' => UserRole::Admin]);

        $this->post('/login', [
            'username' => $admin->username,
            'password' => 'password',
        ])->assertRedirect(route('mfa.setup'));

        $this->assertAuthenticatedAs($admin);
        $this->get('/admin')->assertRedirect(route('mfa.setup'));
    }

    public function test_user_can_enroll_and_complete_totp_mfa(): void
    {
        $admin = User::factory()->create(['category_id' => UserRole::Admin]);
        $totp = app(TotpService::class);

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => false])
            ->get(route('mfa.setup'))
            ->assertOk();

        $secret = session('auth.mfa_setup_secret');

        $this->actingAs($admin)
            ->withSession([
                'auth.mfa_passed' => false,
                'auth.mfa_setup_secret' => $secret,
            ])
            ->post(route('mfa.setup.confirm'), [
                'password' => 'password',
                'code' => $totp->currentCode($secret),
            ])
            ->assertOk()
            ->assertSee('Save your recovery codes');

        $this->assertTrue($admin->fresh()->hasMfaEnabled());
    }

    public function test_enabled_mfa_requires_a_second_factor_and_accepts_totp(): void
    {
        $totp = app(TotpService::class);
        $secret = $totp->generateSecret();
        $admin = User::factory()->create([
            'category_id' => UserRole::Admin,
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ]);

        $this->post('/login', [
            'username' => $admin->username,
            'password' => 'password',
        ])->assertRedirect(route('mfa.challenge'));

        $this->post(route('mfa.challenge.verify'), [
            'code' => $totp->currentCode($secret),
        ])->assertRedirect('/dashboard');

        $this->get('/dashboard')->assertRedirect();
    }

    public function test_recovery_code_is_single_use(): void
    {
        $totp = app(TotpService::class);
        $secret = $totp->generateSecret();
        $recovery = 'ABCDE-FGHIJ';
        $admin = User::factory()->create([
            'category_id' => UserRole::Admin,
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => [Hash::make($recovery)],
            'two_factor_confirmed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => false])
            ->post(route('mfa.challenge.verify'), ['recovery_code' => $recovery])
            ->assertRedirect('/dashboard');

        $this->assertSame([], $admin->fresh()->two_factor_recovery_codes);
    }

    public function test_private_access_can_be_restricted_by_cidr(): void
    {
        Config::set('security.private_access.ip_restriction_enabled', true);
        Config::set('security.private_access.allowed_ips', ['10.20.30.0/24']);
        Config::set('security.private_access.emergency_ips', []);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->get('/login')
            ->assertForbidden();

        $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])
            ->get('/login')
            ->assertOk();
    }

    public function test_public_and_private_routes_are_separated_by_host(): void
    {
        Config::set('security.hosts.enforce_separation', true);
        Config::set('security.hosts.public', ['public.test']);
        Config::set('security.hosts.private', ['private.test']);

        $this->get('http://public.test/thankyou')->assertOk();
        $this->get('http://public.test/login')->assertNotFound();
        $this->get('http://private.test/login')->assertOk();
        $this->get('http://private.test/')->assertNotFound();
    }

    public function test_print_service_requires_both_service_and_station_credentials(): void
    {
        Config::set('security.print_service.authentication_required', true);
        Config::set('security.print_service.client_id', 'service-id');
        Config::set('security.print_service.client_secret', 'service-secret');
        $token = 'ps_station_token';

        PrintStation::create([
            'name' => 'Reception',
            'token_hash' => PrintStation::hashToken($token),
            'enabled' => true,
            'printer_map' => ['counter' => 'COUNTER'],
        ]);

        $this->withToken($token)
            ->postJson('/api/print-station/heartbeat')
            ->assertUnauthorized();

        $this->withToken($token)
            ->withHeaders([
                'CF-Access-Client-Id' => 'service-id',
                'CF-Access-Client-Secret' => 'service-secret',
            ])
            ->postJson('/api/print-station/heartbeat')
            ->assertOk();
    }

    public function test_sensitive_user_edit_requires_recent_step_up(): void
    {
        $admin = User::factory()->create(['category_id' => UserRole::Admin]);
        $other = User::factory()->create();

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->get(route('admin.users.edit', $other))
            ->assertRedirect(route('password.confirm'));

        $this->actingAs($admin)
            ->withSession([
                'auth.mfa_passed' => true,
                'auth.step_up_at' => time(),
            ])
            ->get(route('admin.users.edit', $other))
            ->assertOk();
    }
}

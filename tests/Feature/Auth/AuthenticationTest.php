<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered()
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen()
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'username' => $user->username,
            'password' => 'password',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(RouteServiceProvider::HOME);
        $this->assertAuthenticated();
    }

    public function test_users_can_not_authenticate_with_invalid_password()
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'username' => $user->username,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_pin_only_login_is_rejected(): void
    {
        $this->post('/login', ['pin' => '4826'])
            ->assertSessionHasErrors(['username', 'password']);

        $this->assertGuest();
    }

    public function test_inactive_users_cannot_authenticate(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->post('/login', [
            'username' => $user->username,
            'password' => 'password',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_an_existing_session_is_terminated_after_account_deactivation(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_failed_login_attempts_are_rate_limited_by_account_and_ip(): void
    {
        $username = 'missing-user';
        $accountKey = "login|{$username}|127.0.0.1";
        $ipKey = 'login-ip|127.0.0.1';
        RateLimiter::clear($accountKey);
        RateLimiter::clear($ipKey);

        foreach (range(1, 5) as $attempt) {
            $this->from('/login')->post('/login', [
                'username' => $username,
                'password' => 'InvalidPassword123',
            ]);
        }

        $this->assertSame(5, RateLimiter::attempts($accountKey));
        $this->assertSame(5, RateLimiter::attempts($ipKey));
        $this->from('/login')
            ->post('/login', [
                'username' => $username,
                'password' => 'InvalidPassword123',
            ])
            ->assertSessionHasErrors('username');
    }
}

<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Helpers\ModuleHelper;
use App\Services\AuditLogger;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'username' => Str::lower(trim((string) $this->input('username'))),
        ]);
    }

    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (!Auth::attempt([
            'username' => $this->input('username'),
            'password' => $this->input('password'),
            'is_active' => true,
        ])) {
            $this->recordFailedAttempt();
            $this->auditLoginFailure('Invalid credentials or inactive account.');
            $this->fail('username', trans('auth.failed'));
        }

        $user = Auth::user();

        $this->enforceRoleRestrictions($user);

        $user->forceFill(['last_login_at' => now()])->save();

        $this->session()->put('auth.mfa_passed', !$user->mustCompleteMfa());
        $this->session()->forget(['auth.mfa_verified_at', 'auth.step_up_at']);

        RateLimiter::clear($this->throttleKey());
    }

    protected function enforceRoleRestrictions($user): void
    {
        if ($user->isWaiter() && !ModuleHelper::isWaiterModuleEnabled()) {
            $this->auditLoginFailure('Waiter module disabled.', $user);
            $this->forceLogoutIfLoggedIn();
            $this->fail('username', 'This account cannot sign in while the waiter module is disabled.');
        }

        if ($user->isKitchen() && !ModuleHelper::isKitchenModuleEnabled()) {
            $this->auditLoginFailure('Kitchen module disabled.', $user);
            $this->forceLogoutIfLoggedIn();
            $this->fail('username', 'This account cannot sign in while the kitchen module is disabled.');
        }
    }

    protected function forceLogoutIfLoggedIn(): void
    {
        if (Auth::check()) {
            Auth::logout();
        }
    }

    protected function ensureIsNotRateLimited(): void
    {
        if (
            !RateLimiter::tooManyAttempts($this->throttleKey(), 5)
            && !RateLimiter::tooManyAttempts($this->ipThrottleKey(), 20)
        ) {
            return;
        }

        event(new Lockout($this));

        $seconds = max(
            RateLimiter::availableIn($this->throttleKey()),
            RateLimiter::availableIn($this->ipThrottleKey()),
        );

        $this->fail('username', trans('auth.throttle', [
            'seconds' => $seconds,
            'minutes' => ceil($seconds / 60),
        ]));
    }

    protected function fail(string $field, string $message): void
    {
        throw ValidationException::withMessages([
            $field => [$message],
        ]);
    }

    protected function throttleKey(): string
    {
        return 'login|' . Str::lower((string) $this->input('username')) . '|' . $this->ip();
    }

    protected function ipThrottleKey(): string
    {
        return 'login-ip|' . $this->ip();
    }

    protected function recordFailedAttempt(): void
    {
        RateLimiter::hit($this->throttleKey(), 60);
        RateLimiter::hit($this->ipThrottleKey(), 60);
    }

    private function auditLoginFailure(string $reason, $user = null): void
    {
        app(AuditLogger::class)->record('login_failure', 'authentication', [
            'severity' => 'warning',
            'user' => $user,
            'subject' => $user,
            'request' => $this,
            'metadata' => [
                'summary' => 'Login attempt failed.',
                'username' => $this->input('username'),
                'reason' => $reason,
            ],
        ]);
    }
}

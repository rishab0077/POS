<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Providers\RouteServiceProvider;
use App\Services\AuditLogger;
use App\Services\TotpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class MfaController extends Controller
{
    public function setup(Request $request, TotpService $totp)
    {
        if ($request->user()->hasMfaEnabled()) {
            return redirect()->route('security.index');
        }

        $secret = $request->session()->get('auth.mfa_setup_secret');

        if (!$secret) {
            $secret = $totp->generateSecret();
            $request->session()->put('auth.mfa_setup_secret', $secret);
        }

        return view('auth.mfa-setup', [
            'secret' => $secret,
            'provisioningUri' => $totp->provisioningUri($secret, $request->user()->username),
            'required' => $request->user()->requiresMfa(),
        ]);
    }

    public function confirmSetup(Request $request, TotpService $totp, AuditLogger $audit)
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        if (!Auth::guard('web')->validate([
            'username' => $request->user()->username,
            'password' => $data['password'],
            'is_active' => true,
        ])) {
            throw ValidationException::withMessages(['password' => __('auth.password')]);
        }

        $secret = (string) $request->session()->get('auth.mfa_setup_secret');

        if ($secret === '' || !$totp->verify($secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'The authentication code is invalid.']);
        }

        $recoveryCodes = $totp->generateRecoveryCodes();

        $request->user()->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => collect($recoveryCodes)
                ->map(fn (string $code) => Hash::make($code))
                ->all(),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $request->session()->forget('auth.mfa_setup_secret');
        $request->session()->put('auth.mfa_passed', true);
        $request->session()->put('auth.mfa_verified_at', time());
        $request->session()->put('auth.step_up_at', time());

        $audit->record('mfa_setup_confirmed', 'authentication', [
            'user' => $request->user(),
            'subject' => $request->user(),
            'metadata' => ['summary' => 'MFA setup confirmed.'],
        ]);

        return view('auth.mfa-recovery-codes', ['recoveryCodes' => $recoveryCodes]);
    }

    public function challenge(Request $request)
    {
        if (!$request->user()->hasMfaEnabled()) {
            return redirect()->route('mfa.setup');
        }

        if ((bool) $request->session()->get('auth.mfa_passed', false)) {
            return redirect()->intended(RouteServiceProvider::HOME);
        }

        return view('auth.mfa-challenge');
    }

    public function verifyChallenge(Request $request, TotpService $totp)
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'required_without:code'],
        ]);
        $key = 'mfa|' . $request->user()->id . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'code' => 'Too many attempts. Try again in ' . RateLimiter::availableIn($key) . ' seconds.',
            ]);
        }

        $valid = filled($data['code'] ?? null)
            ? $totp->verify((string) $request->user()->two_factor_secret, $data['code'])
            : $this->consumeRecoveryCode($request, (string) $data['recovery_code']);

        if (!$valid) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages([
                filled($data['code'] ?? null) ? 'code' : 'recovery_code' => 'The authentication code is invalid.',
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate(true);
        $request->session()->put('auth.mfa_passed', true);
        $request->session()->put('auth.mfa_verified_at', time());

        return redirect()->intended(RouteServiceProvider::HOME);
    }

    private function consumeRecoveryCode(Request $request, string $providedCode): bool
    {
        $codes = $request->user()->two_factor_recovery_codes ?: [];
        $providedCode = strtoupper(trim($providedCode));

        foreach ($codes as $index => $hash) {
            if (Hash::check($providedCode, $hash)) {
                unset($codes[$index]);
                $request->user()->forceFill([
                    'two_factor_recovery_codes' => array_values($codes),
                ])->save();

                return true;
            }
        }

        return false;
    }
}

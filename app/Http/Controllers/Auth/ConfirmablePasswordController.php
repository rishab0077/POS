<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Providers\RouteServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use App\Services\TotpService;
use App\Services\AuditLogger;

class ConfirmablePasswordController extends Controller
{
    /**
     * Show the confirm password view.
     *
     * @return \Illuminate\View\View
     */
    public function show()
    {
        return view('auth.confirm-password');
    }

    /**
     * Confirm the user's password.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return mixed
     */
    public function store(Request $request, TotpService $totp, AuditLogger $audit)
    {
        if (! Auth::guard('web')->validate([
            'username' => $request->user()->username,
            'password' => $request->password,
            'is_active' => true,
        ])) {
            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        if ($request->user()->hasMfaEnabled() && !$totp->verify(
            (string) $request->user()->two_factor_secret,
            (string) $request->input('code'),
        )) {
            throw ValidationException::withMessages([
                'code' => 'The authentication code is invalid.',
            ]);
        }

        $request->session()->put('auth.password_confirmed_at', time());
        $request->session()->put('auth.step_up_at', time());

        $audit->record('step_up_confirmed', 'authentication', [
            'user' => $request->user(),
            'subject' => $request->user(),
            'metadata' => ['summary' => 'Step-up authentication confirmed.'],
        ]);

        return redirect($request->session()->pull('auth.step_up_return_to', RouteServiceProvider::HOME));
    }
}

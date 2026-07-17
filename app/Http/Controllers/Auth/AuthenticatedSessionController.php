<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Providers\RouteServiceProvider;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     *
     * @return \Illuminate\View\View
     */
    public function create()
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     *
     * @param  \App\Http\Requests\Auth\LoginRequest  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(LoginRequest $request, AuditLogger $audit)
    {
        $request->authenticate();

        $request->session()->regenerate(true);

        $audit->record('login_success', 'authentication', [
            'user' => $request->user(),
            'subject' => $request->user(),
            'metadata' => [
                'summary' => 'User signed in.',
                'username' => $request->user()->username,
                'mfa_required' => $request->user()->mustCompleteMfa(),
            ],
        ]);

        if ($request->user()->mustCompleteMfa()) {
            return $request->user()->hasMfaEnabled()
                ? redirect()->route('mfa.challenge')
                : redirect()->route('mfa.setup');
        }

        return redirect()->intended(RouteServiceProvider::HOME);
    }

    /**
     * Destroy an authenticated session.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Request $request, AuditLogger $audit)
    {
        $user = $request->user();

        if ($user) {
            $audit->record('logout', 'authentication', [
                'user' => $user,
                'subject' => $user,
                'metadata' => ['summary' => 'User signed out.'],
            ]);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\SessionSecurityService;
use App\Services\AuditLogger;
use App\Services\TotpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class SecurityController extends Controller
{
    public function __construct()
    {
        $this->middleware('stepup')->only([
            'regenerateRecoveryCodes',
            'disableMfa',
            'destroySession',
            'destroyOtherSessions',
        ]);
    }

    public function index(Request $request)
    {
        $sessions = collect();

        if (Schema::hasTable(config('session.table', 'sessions'))) {
            $sessions = DB::table(config('session.table', 'sessions'))
                ->where('user_id', $request->user()->id)
                ->orderByDesc('last_activity')
                ->get();
        }

        return view('security.index', [
            'sessions' => $sessions,
            'currentSessionId' => $request->session()->getId(),
        ]);
    }

    public function regenerateRecoveryCodes(Request $request, TotpService $totp, AuditLogger $audit)
    {
        abort_unless($request->user()->hasMfaEnabled(), 422, 'MFA is not enabled.');
        $recoveryCodes = $totp->generateRecoveryCodes();

        $request->user()->forceFill([
            'two_factor_recovery_codes' => collect($recoveryCodes)
                ->map(fn (string $code) => Hash::make($code))
                ->all(),
        ])->save();

        $audit->record('mfa_recovery_codes_regenerated', 'authentication', [
            'user' => $request->user(),
            'subject' => $request->user(),
            'metadata' => ['summary' => 'MFA recovery codes regenerated.'],
        ]);

        return view('auth.mfa-recovery-codes', ['recoveryCodes' => $recoveryCodes]);
    }

    public function disableMfa(Request $request, SessionSecurityService $sessions, AuditLogger $audit)
    {
        abort_if($request->user()->requiresMfa(), 403, 'MFA is mandatory for this role.');

        $request->user()->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $sessions->revokeForUser($request->user(), $request->session()->getId());
        $request->session()->put('auth.mfa_passed', true);

        $audit->record('mfa_disabled', 'authentication', [
            'user' => $request->user(),
            'subject' => $request->user(),
            'metadata' => ['summary' => 'MFA disabled.'],
        ]);

        return redirect()->route('security.index')->with('success', 'Multi-factor authentication disabled.');
    }

    public function destroySession(Request $request, string $sessionId, AuditLogger $audit)
    {
        abort_if(hash_equals($request->session()->getId(), $sessionId), 422, 'Use Log Out to end the current session.');

        if (Schema::hasTable(config('session.table', 'sessions'))) {
            DB::table(config('session.table', 'sessions'))
                ->where('id', $sessionId)
                ->where('user_id', $request->user()->id)
                ->delete();
        }

        $audit->record('session_revoked', 'authentication', [
            'user' => $request->user(),
            'subject' => $request->user(),
            'metadata' => [
                'summary' => 'User revoked a session.',
                'session_id_hash' => hash('sha256', $sessionId),
            ],
        ]);

        return redirect()->route('security.index')->with('success', 'Session revoked.');
    }

    public function destroyOtherSessions(Request $request, SessionSecurityService $sessions, AuditLogger $audit)
    {
        $sessions->revokeForUser($request->user(), $request->session()->getId());

        $audit->record('other_sessions_revoked', 'authentication', [
            'user' => $request->user(),
            'subject' => $request->user(),
            'metadata' => ['summary' => 'User revoked all other sessions.'],
        ]);

        return redirect()->route('security.index')->with('success', 'All other sessions revoked.');
    }
}

<?php

namespace App\Services;

use Illuminate\Http\Request;

class RecentAuthenticationService
{
    public function isRecent(Request $request): bool
    {
        $confirmedAt = (int) $request->session()->get('auth.step_up_at', 0);
        $timeout = (int) config('security.step_up.timeout', 600);

        return $confirmedAt > 0 && (time() - $confirmedAt) < $timeout;
    }
}

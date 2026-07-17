<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SessionSecurityService
{
    public function revokeForUser(User $user, ?string $exceptSessionId = null): void
    {
        if (Schema::hasTable(config('session.table', 'sessions'))) {
            $query = DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id);

            if ($exceptSessionId) {
                $query->where('id', '!=', $exceptSessionId);
            }

            $query->delete();
        }

        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
    }
}

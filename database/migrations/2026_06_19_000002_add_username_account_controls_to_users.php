<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 64)->nullable()->after('name');
            $table->boolean('is_active')->default(true)->after('category_id');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
            $table->timestamp('password_changed_at')->nullable()->after('last_login_at');
        });

        $usedUsernames = [];

        DB::table('users')
            ->select(['id', 'name', 'email'])
            ->orderBy('id')
            ->each(function ($user) use (&$usedUsernames) {
                $emailPrefix = Str::before((string) $user->email, '@');
                $base = Str::lower(Str::slug($emailPrefix ?: $user->name, '_'));
                $base = preg_replace('/[^a-z0-9._-]/', '', $base) ?: 'user';
                $base = substr($base, 0, 48);
                $username = $base;
                $suffix = 1;

                while (isset($usedUsernames[$username])) {
                    $extra = '_' . $user->id . ($suffix > 1 ? '_' . $suffix : '');
                    $username = substr($base, 0, 64 - strlen($extra)) . $extra;
                    $suffix++;
                }

                $usedUsernames[$username] = true;

                DB::table('users')->where('id', $user->id)->update([
                    'username' => $username,
                    'password_changed_at' => now(),
                ]);
            });

        Schema::table('users', function (Blueprint $table) {
            $table->unique('username');
        });

        DB::table('users')->update([
            'pin' => null,
            'pin_lookup' => null,
            'pin_hash' => null,
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn([
                'username',
                'is_active',
                'last_login_at',
                'password_changed_at',
            ]);
        });
    }
};

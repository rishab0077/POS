<?php

namespace Database\Seeders;

use App\Models\EmployeeCategory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RolesAndAdminSeeder extends Seeder
{
    public function run()
    {
        $roles = [
            1 => 'Admin',
            2 => 'Waiter',
            3 => 'Kitchen',
            4 => 'Biller',
            5 => 'Owner',
            6 => 'Stock Manager',
            7 => 'Manager',
        ];

        foreach ($roles as $id => $name) {
            EmployeeCategory::updateOrCreate(['id' => $id], ['name' => $name]);
        }

        $adminPassword = env('ADMIN_PASSWORD');

        if (!$adminPassword) {
            $adminPassword = Str::random(32);
            $this->command?->warn('ADMIN_PASSWORD is not set. A random admin password was generated for this seed run.');
        }

        $admin = User::firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@example.com')],
            [
                'name' => env('ADMIN_NAME', 'Admin'),
                'username' => Str::lower(env('ADMIN_USERNAME', 'admin')),
                'email_verified_at' => now(),
                'password' => Hash::make($adminPassword),
                'remember_token' => Str::random(10),
                'category_id' => 1,
                'is_active' => true,
                'password_changed_at' => now(),
            ]
        );

        if (!$admin->wasRecentlyCreated) {
            $admin->forceFill([
                'name' => env('ADMIN_NAME', 'Admin'),
                'category_id' => 1,
                'is_active' => true,
            ])->save();
        }
    }
}

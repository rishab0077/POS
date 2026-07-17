<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class BasicSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        $this->call(RolesAndAdminSeeder::class);
        $this->call(RestaurantSeeder::class);
        $this->call(InventoryCategorySeeder::class);
    }
}

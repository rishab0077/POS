<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
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
        $this->call(TableSeeder::class);
        $this->call(MenuSeeder::class);
    }
}

<?php

namespace Database\Seeders;

use App\Models\Restaurant;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RestaurantSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        //
        // Environment values bootstrap new installations. Existing database
        // settings remain authoritative and survive subsequent deployments.
        Restaurant::firstOrCreate([], [
            'name' => env('BUSINESS_NAME', 'Restaurant POS'),
            'tagline' => 'Good food, warm service',
            'address' => env('BUSINESS_ADDRESS', 'Kathmandu, Nepal - TO BE UPDATED'),
            'phone' => '123-456-7890',
            'GST' => env('BUSINESS_PAN', 'TO BE UPDATED'),
            'email' => env('BUSINESS_EMAIL', 'admin@example.com'),
            'website' => env('APP_URL', 'http://localhost'),
            'takeout_enabled' => 1,
            'delivery_enabled' => 1,
            'pending_order_sync_time' => 5,
            'waiter_sync_time' => 5,
            'minimum_delivery_time' => 30, // minutes
            'minimum_preparation_time' => 20, // minutes
            'order_live_view' => 'desc',
            'kot_live_view' => 'asc',
            'payment_options' => json_encode(array_keys(config('pos.payments'))),
            'social_media' => null,
            'tax_rate' => config('pos.tax.vat_rate', 13),
            'currency_symbol' => 'NPR',
            'reservation_enabled' => 1,
            'reservation_advance_notice' => 120, // minutes
            'created_at' => now(),
            'updated_at' => now(),
            'waiter_module_enabled' => true,
            'kitchen_module_enabled' => true,
            'biller_printer' => null,
            'kitchen_printer' => null,
        ]);
    }
}

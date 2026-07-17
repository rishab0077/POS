<?php

namespace Database\Seeders;

use App\Modules\Inventory\Models\StockCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class InventoryCategorySeeder extends Seeder
{
    public function run()
    {
        foreach ([
            'Kitchen Ingredients',
            'Beverages',
            'Alcohol',
            'Packaging',
        ] as $category) {
            StockCategory::updateOrCreate(
                ['slug' => Str::slug($category)],
                ['name' => $category, 'active' => true]
            );
        }
    }
}

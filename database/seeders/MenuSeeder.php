<?php

namespace Database\Seeders;

use App\Enums\MenuType;
use App\Models\Category;
use App\Models\Menu;
use Illuminate\Database\Seeder;

class MenuSeeder extends Seeder
{
    public function run(): void
    {
        $categories = collect([
            ['name' => 'specials', 'rank' => 1],
            ['name' => 'veg', 'rank' => 2],
            ['name' => 'non-veg', 'rank' => 3],
            ['name' => 'beverages', 'rank' => 4],
        ])->mapWithKeys(function (array $category) {
            $model = Category::updateOrCreate(
                ['name' => $category['name']],
                ['rank' => $category['rank']]
            );

            return [$category['name'] => $model];
        });

        $menuItems = [
            ['category' => 'specials', 'name' => 'Mutton Curry', 'shortcode' => 'mc', 'price' => 250.00, 'type' => MenuType::Service],
            ['category' => 'specials', 'name' => 'Chicken Curry', 'shortcode' => 'cc', 'price' => 140.00, 'type' => MenuType::Service],
            ['category' => 'veg', 'name' => 'Veg Curry', 'shortcode' => 'vc', 'price' => 150.00, 'type' => MenuType::Service],
            ['category' => 'veg', 'name' => 'Veg Meals', 'shortcode' => 'vm', 'price' => 200.00, 'type' => MenuType::Service],
            ['category' => 'non-veg', 'name' => 'CB Single', 'shortcode' => 'cbs', 'price' => 130.00, 'type' => MenuType::Service],
            ['category' => 'non-veg', 'name' => 'CB Full', 'shortcode' => 'cbf', 'price' => 250.00, 'type' => MenuType::Service],
            ['category' => 'beverages', 'name' => 'Water Bottle', 'shortcode' => 'b', 'price' => 20.00, 'type' => MenuType::Stock, 'quantity' => 50],
            ['category' => 'beverages', 'name' => 'Cool Drink 250ml', 'shortcode' => 'c', 'price' => 20.00, 'type' => MenuType::Stock, 'quantity' => 50],
        ];

        foreach ($menuItems as $menuItem) {
            $menu = Menu::updateOrCreate(
                ['shortcode' => $menuItem['shortcode']],
                [
                    'name' => $menuItem['name'],
                    'price' => $menuItem['price'],
                    'type' => $menuItem['type'],
                    'quantity' => $menuItem['quantity'] ?? null,
                ]
            );

            $menu->category()->syncWithoutDetaching([
                $categories[$menuItem['category']]->id,
            ]);
        }
    }
}

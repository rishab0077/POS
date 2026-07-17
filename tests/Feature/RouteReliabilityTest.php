<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Menu;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Table;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RouteReliabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_previously_broken_routes_have_intentional_behavior(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('category_id', 1)->firstOrFail();
        $menu = Menu::where('shortcode', 'mc')->firstOrFail();
        $category = $menu->category()->firstOrFail();
        $table = Table::where('name', 'T1')->firstOrFail();

        $this->get(route('categories.index'))
            ->assertOk()
            ->assertSee($category->name);
        $this->get(route('categories.show', $category))
            ->assertOk()
            ->assertSee($menu->name);
        $this->get(route('menus.index'))
            ->assertOk()
            ->assertSee($menu->name);
        $this->get(route('request.bill'))
            ->assertOk()
            ->assertSee('Request Your Bill');
        $this->get(route('request.extra'))
            ->assertOk()
            ->assertSee('Request Extra Service');

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->get(route('admin.KOTs'))
            ->assertRedirect(route('order.KOT.view'));

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->getJson(route('admin.restaurant.module.status'))
            ->assertOk()
            ->assertJsonStructure([
                'waiter_module_enabled',
                'kitchen_module_enabled',
            ]);

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->get('/admin/bills/fd')
            ->assertNotFound();

        $this->assertFalse(Route::has('admin.categories.show'));
        $this->assertFalse(Route::has('admin.menus.show'));
        $this->assertFalse(Route::has('admin.tables.show'));
        $this->assertFalse(Route::has('admin.table-location.show'));
        $this->assertFalse(Route::has('admin.reservations.show'));
        $this->assertFalse(Route::has('admin.users.show'));

        $this->getJson('/api/user')->assertNotFound();

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->get('/reporting/view/not-a-report')
            ->assertNotFound();

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->get(route('admin.view.bill', 999999))
            ->assertNotFound();

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->get(route('pos.table.orders', 999999))
            ->assertNotFound();

        $pickupOrder = Order::create([
            'KOT' => 'PICKUP-UPDATE',
            'total' => 100,
            'table_id' => null,
            'status' => OrderStatus::ReadyForPickup->value,
            'order_type' => OrderType::Takeaway->value,
            'waiter_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->getJson(route('sync.pickup.orders', ['lastOrderId' => 0]))
            ->assertOk()
            ->assertJsonPath('hasNewOrders', true);

        $pendingOrder = Order::create([
            'KOT' => 'KITCHEN-PENDING',
            'total' => (float) $menu->price,
            'table_id' => $table->id,
            'status' => OrderStatus::New->value,
            'order_type' => OrderType::DineIn->value,
            'waiter_id' => $admin->id,
        ]);
        OrderDetail::create([
            'order_id' => $pendingOrder->id,
            'menu_id' => $menu->id,
            'quantity' => 1,
            'unit_price' => $menu->price,
        ]);

        $processingOrder = Order::create([
            'KOT' => 'KITCHEN-PROCESSING',
            'total' => (float) $menu->price,
            'table_id' => $table->id,
            'status' => OrderStatus::Processing->value,
            'order_type' => OrderType::DineIn->value,
            'waiter_id' => $admin->id,
        ]);
        OrderDetail::create([
            'order_id' => $processingOrder->id,
            'menu_id' => $menu->id,
            'quantity' => 1,
            'unit_price' => $menu->price,
        ]);

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->getJson(route('kitchen.pending.orders'))
            ->assertOk()
            ->assertJsonCount(1, 'orders')
            ->assertJsonPath('orders.0.id', $pendingOrder->id)
            ->assertJsonCount(1, 'processingOrders')
            ->assertJsonPath('processingOrders.0.id', $processingOrder->id)
            ->assertJsonMissing(['id' => $pickupOrder->id]);

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->get(route('kitchen.index'))
            ->assertOk()
            ->assertSee('WebSockets are primary')
            ->assertSee('reconcileOrderColumn');

        $restaurant = \App\Models\Restaurant::firstOrFail();
        $restaurant->update(['kitchen_module_enabled' => false]);

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->get(route('admin.index'))
            ->assertOk()
            ->assertDontSee('Kitchen View');

        $restaurant->update(['kitchen_module_enabled' => true]);

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->get(route('admin.index'))
            ->assertOk()
            ->assertSee('Kitchen View');
    }
}

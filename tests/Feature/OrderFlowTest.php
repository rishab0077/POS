<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Models\Order;
use App\Models\Table;
use App\Models\User;
use App\Enums\TableStatus;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_can_submit_a_table_order_to_the_kitchen(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('category_id', 1)->firstOrFail();
        $table = Table::where('name', 'T1')->firstOrFail();
        $menu = Menu::where('shortcode', 'mc')->firstOrFail();

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->post(route('order.submit'), [
                'source' => 'pos',
                'tableId' => $table->id,
                'specialInstructions' => [],
                'isPickUpOrder' => 'false',
                'paymentMethod' => 'cash',
                'billTable' => 'false',
                'order' => [
                    'orderItems' => [[
                        'id' => $menu->id,
                        'name' => $menu->name,
                        'quantity' => 1,
                        'price' => 0.01,
                        'total' => 0.01,
                    ]],
                    'total' => 0.01,
                    'discount' => 0,
                    'grandtotal' => 0.01,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $order = Order::firstOrFail();

        $this->assertSame($table->id, $order->table_id);
        $this->assertSame(1, $order->orderDetails()->count());
        $this->assertSame((float) $menu->price, (float) $order->total);
        $this->assertSame((float) $menu->price, (float) $order->orderDetails()->first()->unit_price);
        $this->assertSame('running', $table->fresh()->status->value);
    }

    public function test_finalized_table_rejects_new_orders(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('category_id', 1)->firstOrFail();
        $table = Table::where('name', 'T1')->firstOrFail();
        $menu = Menu::where('shortcode', 'mc')->firstOrFail();
        $table->update(['status' => TableStatus::Printed]);

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->postJson(route('order.submit'), [
                'source' => 'pos',
                'tableId' => $table->id,
                'specialInstructions' => [],
                'isPickUpOrder' => 'false',
                'paymentMethod' => 'cash',
                'billTable' => 'false',
                'order' => [
                    'orderItems' => [[
                        'id' => $menu->id,
                        'quantity' => 1,
                    ]],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tableId');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_waiter_order_screen_receives_the_active_table_total(): void
    {
        $this->seed(DatabaseSeeder::class);

        $waiter = User::factory()->create(['category_id' => UserRole::Waiter]);
        $table = Table::where('name', 'T1')->firstOrFail();
        $table->update(['status' => TableStatus::Running, 'taken_at' => now()]);

        Order::create([
            'KOT' => 'KOT-RUNNING',
            'total' => 125.50,
            'table_id' => $table->id,
            'status' => OrderStatus::New,
            'order_type' => 'dine_in',
            'waiter_id' => $waiter->id,
        ]);
        Order::create([
            'KOT' => 'KOT-CANCELLED',
            'total' => 999.00,
            'table_id' => $table->id,
            'status' => OrderStatus::Cancelled,
            'order_type' => 'dine_in',
            'waiter_id' => $waiter->id,
        ]);

        $this->actingAs($waiter)
            ->withSession(['auth.mfa_passed' => true])
            ->get(route('waiter.order', $table->id))
            ->assertOk()
            ->assertViewHas('existingTableTotal', 125.50)
            ->assertSee('Current table')
            ->assertSee('After adding');
    }
}

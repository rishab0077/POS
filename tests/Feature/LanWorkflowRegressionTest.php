<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Helpers\KitchenHelper;
use App\Http\Service\ReportingService;
use App\Models\Bill;
use App\Models\Menu;
use App\Models\Order;
use App\Models\Table;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use App\Events\OrderSubmittedToKitchen;
use Tests\TestCase;

class LanWorkflowRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('category_id', 1)->firstOrFail())
            ->withSession(['auth.mfa_passed' => true, 'auth.step_up_at' => time()]);
    }

    private function submit(array $overrides = [])
    {
        return $this->postJson(route('order.submit'), array_replace([
            'source' => 'pos',
            'tableId' => Table::where('name', 'T1')->value('id'),
            'isPickUpOrder' => 'false',
            'paymentMethod' => 'cash',
            'billTable' => 'false',
            'order' => ['orderItems' => [['id' => Menu::where('shortcode', 'mc')->value('id'), 'quantity' => 1]]],
        ], $overrides));
    }

    private function finalize(): Bill
    {
        $this->postJson(route('pos.table.bill'), [
            'tableId' => Table::where('name', 'T1')->value('id'),
            'billAction' => 'final', 'paymentType' => 'cash',
        ])->assertOk();
        return Bill::whereNotNull('locked_at')->firstOrFail();
    }

    public function test_failed_checkout_rolls_back_new_order_and_does_not_broadcast(): void
    {
        Event::fake([OrderSubmittedToKitchen::class]);
        Menu::where('shortcode', 'mc')->update(['price' => 11000]);
        $this->submit(['billTable' => 'true'])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('bills', 0);
        $this->assertSame('available', Table::where('name', 'T1')->firstOrFail()->status->value);
        Event::assertNotDispatched(OrderSubmittedToKitchen::class);
    }

    public function test_cancelling_last_unpaid_ticket_releases_table(): void
    {
        $this->submit()->assertOk();
        $order = Order::firstOrFail();
        $order->update(['status' => OrderStatus::New]);
        $this->postJson(route('kitchen.discard.order'), [
            'orderId' => $order->id, 'reason' => config('pos.cancellation_reasons')[0],
        ])->assertOk();
        $this->assertSame('available', $order->table->fresh()->status->value);
        $this->assertNull($order->table->fresh()->taken_at);
    }

    public function test_invoiced_ticket_cannot_be_cancelled(): void
    {
        $this->submit()->assertOk();
        $order = Order::firstOrFail();
        $order->update(['status' => OrderStatus::New]);
        $this->finalize();
        $this->postJson(route('kitchen.discard.order'), [
            'orderId' => $order->id, 'reason' => config('pos.cancellation_reasons')[0],
        ])->assertUnprocessable();
        $this->assertSame(OrderStatus::New, $order->fresh()->status);
    }

    public function test_settlement_failure_rolls_back_orders_and_table(): void
    {
        $this->submit()->assertOk();
        $bill = $this->finalize();
        Bill::updating(function (Bill $bill) {
            if ($bill->status === 'closed') {
                throw new \RuntimeException('Simulated settlement write failure');
            }
        });
        try {
            $this->postJson(route('pos.table.settle'), ['tableId' => $bill->table_id])->assertStatus(500);
            $this->assertSame('printed', $bill->table->fresh()->status->value);
            $this->assertNotSame(OrderStatus::Closed, Order::firstOrFail()->status);
            $this->assertSame('open', $bill->fresh()->status);
        } finally {
            Bill::flushEventListeners();
        }
    }

    public function test_ticket_numbers_are_reserved_even_before_orders_are_inserted(): void
    {
        $first = KitchenHelper::generateKOT();
        $second = KitchenHelper::generateKOT();
        $this->assertNotSame($first, $second);
    }

    public function test_referenced_menu_cannot_be_deleted(): void
    {
        $this->submit()->assertOk();
        $menu = Menu::where('shortcode', 'mc')->firstOrFail();
        $this->delete(route('admin.menus.destroy', $menu))->assertRedirect();
        $this->assertModelExists($menu);
        $this->assertDatabaseCount('order_details', 1);
    }

    public function test_reports_keep_original_identity_after_menu_changes(): void
    {
        $this->submit()->assertOk();
        $menu = Menu::where('shortcode', 'mc')->firstOrFail();
        $this->finalize();
        $originalName = $menu->name;
        $originalCategory = $menu->category->first()->name;
        $menu->update(['name' => 'Renamed after sale']);
        $menu->category()->detach();
        $items = app(ReportingService::class)->salesByItemReport(now(), now());
        $categories = app(ReportingService::class)->salesByCategoryReport(now(), now());
        $this->assertNotNull(collect($items['data'])->firstWhere('menu', $originalName));
        $this->assertGreaterThan(0, collect($categories['data'])->firstWhere('category', $originalCategory)['total_amount']);
    }

    public function test_cancelling_one_ticket_does_not_release_a_table_with_another_order(): void
    {
        $this->submit()->assertOk();
        $this->submit()->assertOk();
        $order = Order::firstOrFail();
        $order->update(['status' => OrderStatus::New]);
        $this->postJson(route('kitchen.discard.order'), [
            'orderId' => $order->id, 'reason' => config('pos.cancellation_reasons')[0],
        ])->assertOk();
        $this->assertSame('running', $order->table->fresh()->status->value);
    }

    public function test_kot_poll_includes_other_operators_and_existing_status_changes(): void
    {
        $this->submit()->assertOk();
        $order = Order::firstOrFail();
        $operator = User::where('category_id', 1)->firstOrFail();
        $otherOperator = $operator->replicate(['username', 'email']);
        $otherOperator->username = 'another-kot-operator';
        $otherOperator->save();
        $order->update(['waiter_id' => $otherOperator->id, 'status' => OrderStatus::New]);
        $url = route('sync.pending.orders', ['lastOrderId' => $order->id]);
        $this->getJson($url)->assertOk()->assertJsonPath('orders.0.id', $order->id)
            ->assertJsonPath('orders.0.status', 'new');
        $order->update(['status' => OrderStatus::Processing]);
        $this->getJson($url)->assertOk()->assertJsonPath('orders.0.status', 'processing');
        $order->update(['status' => OrderStatus::Served]);
        $this->getJson($url)->assertOk()->assertJsonPath('orders', []);
    }

    public function test_waiter_kot_poll_matches_the_table_only_view(): void
    {
        $this->submit()->assertOk();
        $tableOrder = Order::firstOrFail();
        $tableOrder->update(['status' => OrderStatus::New]);
        $takeaway = $tableOrder->replicate();
        $takeaway->table_id = null;
        $takeaway->source_table_id = null;
        $takeaway->KOT = KitchenHelper::generateKOT();
        $takeaway->save();
        $waiter = User::where('category_id', 1)->firstOrFail()->replicate(['username', 'email']);
        $waiter->username = 'kot-waiter';
        $waiter->category_id = \App\Enums\UserRole::Waiter;
        $waiter->save();
        $this->actingAs($waiter)->getJson(route('sync.pending.orders'))
            ->assertOk()->assertJsonCount(1, 'orders')->assertJsonPath('orders.0.id', $tableOrder->id);
    }
}

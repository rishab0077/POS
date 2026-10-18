<?php

namespace Tests\Feature;

use App\Enums\{OrderStatus, UserRole};
use App\Helpers\BillHelper;
use App\Models\{Bill, Menu, Order, PrintJob, PrintStation, Restaurant, Table, User};
use App\Modules\Inventory\Models\{MenuItemStockMapping, StockCategory, StockItem};
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderCancellationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('category_id', 1)->firstOrFail())
            ->withSession(['auth.mfa_passed' => true]);
    }

    private function ticket(OrderStatus $status = OrderStatus::New): Order
    {
        $this->postJson(route('order.submit'), [
            'source' => 'pos', 'tableId' => Table::where('name', 'T1')->value('id'),
            'isPickUpOrder' => 'false', 'paymentMethod' => 'cash', 'billTable' => 'false',
            'order' => ['orderItems' => [['id' => Menu::where('shortcode', 'mc')->value('id'), 'quantity' => 1]]],
        ])->assertOk();
        $order = Order::latest('id')->firstOrFail();
        $order->update(['status' => $status]);
        return $order;
    }

    private function cancelOne(Order $order, int $expectedQuantity, array $overrides = [])
    {
        return $this->cancel($order, array_replace([
            'orderDetailId' => $order->orderDetails()->firstOrFail()->id,
            'expectedQuantity' => $expectedQuantity,
        ], $overrides));
    }

    public function test_one_unit_cancellation_updates_preview_and_audits_without_changing_ticket_status(): void
    {
        $order = $this->ticket(OrderStatus::Processing);
        $detail = $order->orderDetails()->firstOrFail();
        $detail->update(['quantity' => 3]);
        $order->update(['total' => (float) $detail->unit_price * 3]);
        $billId = BillHelper::createTableBill($order->table_id);
        $waiter = $this->staff(UserRole::Waiter);
        $this->actingAs($waiter);

        $this->cancelOne($order, 3)->assertOk();

        $this->assertSame(2, (int) $detail->fresh()->quantity);
        $this->assertSame(OrderStatus::Processing, $order->fresh()->status);
        $this->assertSame((float) $detail->unit_price * 2, (float) $order->fresh()->total);
        $this->assertSame((float) $order->fresh()->total, (float) Bill::findOrFail($billId)->bill_amount);
        $this->assertSame('running', $order->table->fresh()->status->value);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'order_item_cancelled', 'user_id' => $waiter->id]);
        $this->assertDatabaseCount('order_details', 1);
    }

    public function test_last_unit_cancels_ticket_and_preserves_the_zero_quantity_row(): void
    {
        $order = $this->ticket(OrderStatus::Served);
        $detail = $order->orderDetails()->firstOrFail();
        $billId = BillHelper::createTableBill($order->table_id);

        $this->cancelOne($order, 1)->assertOk();

        $this->assertSame(0, (int) $detail->fresh()->quantity);
        $this->assertCount(0, $order->fresh()->orderDetails);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame('available', $order->table->fresh()->status->value);
        $this->assertSoftDeleted('bills', ['id' => $billId]);
        $this->assertDatabaseCount('order_details', 1);
        $this->assertDatabaseCount('audit_events', 2);
    }

    public function test_cancelled_line_is_absent_from_kot_and_final_invoice_for_remaining_items(): void
    {
        $order = $this->ticket();
        $removed = $order->orderDetails()->firstOrFail();
        $other = Menu::where('id', '!=', $removed->menu_id)->firstOrFail();
        $order->orderDetails()->create(['menu_id' => $other->id, 'quantity' => 2, 'unit_price' => $other->price]);
        $order->update(['total' => (float) $removed->unit_price + 2 * (float) $other->price]);
        $this->cancelOne($order, 1)->assertOk();

        $this->assertSame(OrderStatus::New, $order->fresh()->status);
        $this->assertFalse(\App\Helpers\KitchenHelper::getKOTOrders($order->KOT)->has($removed->menu->name));
        $bill = BillHelper::finalizeBill(BillHelper::createTableBill($order->table_id), 'cash');
        $this->assertSame(2 * (float) $other->price, (float) $bill->bill_amount);
        $this->assertDatabaseMissing('fiscal_invoice_items', ['source_order_detail_id' => $removed->id]);
        $this->assertDatabaseHas('fiscal_invoice_items', ['item_name' => $other->name, 'quantity' => 2]);
    }

    public function test_stale_and_cross_ticket_item_cancellations_do_not_remove_more_food(): void
    {
        $order = $this->ticket();
        $detail = $order->orderDetails()->firstOrFail();
        $detail->update(['quantity' => 3]);
        $order->update(['total' => 3 * (float) $detail->unit_price]);
        $otherOrder = $this->ticket();

        $this->cancelOne($order, 3, ['orderDetailId' => $otherOrder->orderDetails()->first()->id])->assertNotFound();
        $this->cancelOne($order, 3)->assertOk();
        $this->cancelOne($order, 3)->assertUnprocessable();
        $this->cancelOne($order, 2, ['reason' => ''])->assertUnprocessable();
        $this->cancelOne($order, 2, ['expectedQuantity' => null])->assertUnprocessable();
        $this->assertSame(2, (int) $detail->fresh()->quantity);
    }

    public function test_item_cancellation_rejects_finalized_and_soft_deleted_final_invoices(): void
    {
        $order = $this->ticket();
        $bill = BillHelper::finalizeBill(BillHelper::createTableBill($order->table_id), 'cash');
        $this->cancelOne($order, 1)->assertUnprocessable();
        $bill->delete();
        $this->cancelOne($order, 1)->assertUnprocessable();
        $this->assertSame(1, (int) $order->orderDetails()->first()->quantity);
    }

    public function test_one_unit_cancellation_refreshes_unfinished_print_payloads_and_blocks_empty_area_retry(): void
    {
        $order = $this->ticket();
        $detail = $order->orderDetails()->firstOrFail();
        $detail->update(['quantity' => 3]);
        $order->update(['total' => 3 * (float) $detail->unit_price]);
        $station = PrintStation::create(['name' => 'Item cancellation printer', 'enabled' => true,
            'token_hash' => hash('sha256', 'item-cancel-test'), 'printer_map' => ['kitchen' => 'Printer']]);
        $job = PrintJob::create(['idempotency_key' => 'partial-cancel', 'type' => 'kot', 'printer_key' => 'kitchen',
            'source_type' => Order::class, 'source_id' => $order->id, 'status' => 'processing',
            'print_station_id' => $station->id, 'locked_until' => now()->subMinute(),
            'payload_format' => 'escpos_base64', 'payload' => base64_encode('stale ticket')]);
        $this->cancelOne($order, 3)->assertOk();
        $this->assertSame(app(\App\Services\PrintPayloadService::class)
            ->kotPayload($order->KOT, null, 'kitchen', 'KOT'), $job->fresh()->payload);
        $this->assertSame(1, PrintJob::availableForStation($station)->count());

        $other = Menu::where('id', '!=', $detail->menu_id)->firstOrFail();
        $barCategory = \App\Models\Category::create(['name' => 'Cancellation bar', 'print_destination' => 'bot', 'rank' => 100]);
        $other->category()->sync([$barCategory->id]);
        $order->orderDetails()->create(['menu_id' => $other->id, 'quantity' => 1, 'unit_price' => $other->price]);
        $this->cancelOne($order, 2)->assertOk();
        $this->cancelOne($order, 1)->assertOk();
        $this->assertSame('cancelled', $job->fresh()->status);
        $job->markFailed($station, 'Late failure acknowledgement');
        $this->assertSame('cancelled', $job->fresh()->status);
        $this->assertFalse($job->retry(auth()->user()));
    }

    public function test_item_cancellation_clamps_preview_rewards_and_rolls_back_on_bill_failure(): void
    {
        $order = $this->ticket();
        $detail = $order->orderDetails()->firstOrFail();
        $detail->update(['quantity' => 2, 'loyalty_reward_quantity' => 2,
            'loyalty_original_unit_price' => $detail->unit_price, 'loyalty_redeemed_by' => auth()->id(),
            'loyalty_redeemed_at' => now()]);
        $order->update(['total' => 0]);
        $billId = BillHelper::createTableBill($order->table_id);
        Bill::saving(function () { throw new \RuntimeException('Simulated preview failure'); });
        try {
            $this->cancelOne($order, 2)->assertStatus(500);
            $this->assertSame(2, (int) $detail->fresh()->quantity);
            $this->assertDatabaseCount('audit_events', 0);
        } finally {
            Bill::flushEventListeners();
        }
        $this->cancelOne($order, 2)->assertOk();
        $this->assertSame(1, (int) $detail->fresh()->loyalty_reward_quantity);
        $this->assertSame(0.0, (float) Bill::findOrFail($billId)->bill_amount);
    }

    public function test_quantity_controls_render_on_waiter_pos_and_kot_and_sync_reports_quantity_changes(): void
    {
        $order = $this->ticket();
        $menu = $order->orderDetails()->firstOrFail()->menu;
        $this->get(route('pos.main', ['tableId' => $order->table_id]))->assertOk()
            ->assertSee('data-cancel-one', false)->assertSee('addItemToOrder(' . $menu->id, false);
        $this->actingAs($this->staff(UserRole::Waiter));
        $this->get(route('waiter.order', $order->table_id))->assertOk()
            ->assertSee('data-cancel-one', false)->assertSee('Confirm cancellation');
        $this->get(route('order.KOT.view'))->assertOk()->assertSee('data-add-one', false);
        $this->actingAs(User::where('category_id', 1)->firstOrFail());
        $this->get(route('kitchen.pending.orders'))->assertOk()->assertJsonPath('orders.0.item_count', 1);
    }

    private function staff(UserRole $role): User
    {
        $user = User::where('category_id', 1)->firstOrFail()->replicate(['username', 'email']);
        $user->username = 'cancel-staff-' . $role->value;
        $user->category_id = $role;
        $user->save();
        return $user;
    }

    private function cancel(Order $order, array $overrides = [])
    {
        return $this->postJson(route('order.cancel'), array_replace([
            'orderId' => $order->id, 'reason' => 'Customer Cancelled Booking', 'notes' => 'Guest called to cancel.',
        ], $overrides));
    }

    public function test_waiter_can_cancel_sent_accepted_ready_and_served_tickets(): void
    {
        $waiter = $this->staff(UserRole::Waiter);
        foreach ([OrderStatus::New, OrderStatus::Processing, OrderStatus::ReadyForPickup, OrderStatus::Served] as $status) {
            $this->actingAs(User::where('category_id', 1)->firstOrFail());
            $order = $this->ticket($status);
            $this->actingAs($waiter);
            $this->cancel($order)->assertOk();
            $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
            $this->assertSame($waiter->id, $order->fresh()->cancelled_by);
            $this->assertSame('available', $order->table->fresh()->status->value);
        }
        $this->assertDatabaseCount('audit_events', 4);
    }

    public function test_waiter_has_cancel_controls_and_table_ticket_link(): void
    {
        $order = $this->ticket(OrderStatus::Served);
        $this->actingAs($this->staff(UserRole::Waiter));
        $this->get(route('waiter.order', $order->table_id))->assertOk()->assertSee('View / cancel existing orders');
        $this->get(route('waiter.table.orders', $order->table_id))->assertOk()
            ->assertSee('Cancel whole ticket')->assertSee('Customer Cancelled Booking')->assertDontSee('Mark As Prepared');
        $order->update(['status' => OrderStatus::New]);
        $this->get(route('order.KOT.view'))->assertOk()->assertSee('Cancel whole ticket');
    }

    public function test_cancellation_works_without_kitchen_module_and_excludes_inventory_staff(): void
    {
        Restaurant::firstOrFail()->update(['kitchen_module_enabled' => false]);
        $this->assertFalse(app(\App\Http\Service\RestaurantService::class)->isKitchenModuleEnabled());
        $order = $this->ticket(OrderStatus::Served);
        $this->actingAs($this->staff(UserRole::StockManager));
        $this->cancel($order)->assertForbidden();
        $this->actingAs($this->staff(UserRole::Biller));
        $this->cancel($order)->assertOk();
    }

    public function test_final_invoices_and_invalid_reasons_cannot_be_cancelled(): void
    {
        $order = $this->ticket();
        $this->cancel($order, ['reason' => ''])->assertUnprocessable();
        $billId = BillHelper::createTableBill($order->table_id);
        BillHelper::finalizeBill($billId, 'cash');
        $this->actingAs($this->staff(UserRole::Waiter));
        $this->cancel($order)->assertUnprocessable();
        $this->assertSame(OrderStatus::New, $order->fresh()->status);
        $this->assertDatabaseCount('fiscal_invoice_snapshots', 1);
    }

    public function test_cancel_recalculates_unpaid_preview_and_keeps_other_tickets(): void
    {
        $first = $this->ticket();
        $second = $this->ticket();
        $billId = BillHelper::createTableBill($first->table_id);
        $this->cancel($first)->assertOk();
        $bill = Bill::findOrFail($billId);
        $this->assertSame((float) $second->total, (float) $bill->bill_amount);
        $this->assertSame([$second->id], $bill->orders->pluck('id')->all());
        $this->assertSame('running', $first->table->fresh()->status->value);
        $this->cancel($second)->assertOk();
        $this->assertSoftDeleted('bills', ['id' => $billId]);
        $this->assertDatabaseCount('order_details', 2);
    }

    public function test_cancel_stops_unsent_print_jobs_and_preserves_stock(): void
    {
        $stock = StockItem::create(['name' => 'Cancel stock', 'category_id' => StockCategory::firstOrFail()->id,
            'unit' => 'kg', 'current_quantity' => 10, 'active' => true, 'auto_deduct' => true]);
        MenuItemStockMapping::create(['stock_item_id' => $stock->id, 'menu_item_id' => Menu::where('shortcode', 'mc')->value('id'),
            'quantity_per_sale' => 1, 'active' => true]);
        $order = $this->ticket();
        $job = PrintJob::create(['idempotency_key' => 'cancel-ticket', 'type' => 'kot', 'printer_key' => 'kitchen',
            'source_type' => Order::class, 'source_id' => $order->id, 'status' => 'pending',
            'payload_format' => 'escpos_base64', 'payload' => base64_encode('test')]);
        $this->cancel($order)->assertOk();
        $this->assertSame('cancelled', $job->fresh()->status);
        $this->assertSame(10.0, (float) $stock->fresh()->current_quantity);
        $station = PrintStation::create(['name' => 'Cancel test station', 'enabled' => true,
            'token_hash' => hash('sha256', 'cancel-test'), 'printer_map' => ['kitchen' => 'Printer']]);
        $this->assertSame(0, PrintJob::availableForStation($station)->count());
        $job->update(['status' => 'failed']);
        $this->assertFalse($job->retry(auth()->user()));
    }

    public function test_stale_buttons_cannot_revive_a_cancelled_ticket(): void
    {
        $order = $this->ticket();
        $this->cancel($order)->assertOk();
        foreach (['order.mark.as.served', 'order.mark.as.prepared', 'order.mark.as.closed', 'kitchen.accept.order', 'kitchen.complete.order'] as $route) {
            $this->postJson(route($route), ['orderId' => $order->id])->assertUnprocessable();
        }
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->cancel($order)->assertUnprocessable();
    }

    public function test_closing_an_unpaid_ticket_cannot_bypass_cancellation(): void
    {
        $order = $this->ticket();
        $this->postJson(route('order.mark.as.closed'), ['orderId' => $order->id])->assertUnprocessable();
        $this->assertSame(OrderStatus::New, $order->fresh()->status);
    }

    public function test_legacy_soft_deleted_final_invoice_still_blocks_cancellation(): void
    {
        $order = $this->ticket();
        $billId = BillHelper::createTableBill($order->table_id);
        $bill = BillHelper::finalizeBill($billId, 'cash');
        $bill->delete();
        $this->cancel($order)->assertUnprocessable();
        $this->assertSame(OrderStatus::New, $order->fresh()->status);
    }

    public function test_cancelling_last_unpaid_source_keeps_paid_tickets_finalized(): void
    {
        $paid = $this->ticket();
        $unpaid = $this->ticket();
        $unpaid->update(['source_table_id' => Table::where('name', 'T2')->value('id')]);
        $billId = BillHelper::createTableBill($paid->table_id, null, null, 0, [], $paid->source_table_id);
        BillHelper::finalizeBill($billId, 'cash');
        $this->cancel($unpaid)->assertOk();
        $this->assertSame('printed', $paid->table->fresh()->status->value);
        $this->assertSame(OrderStatus::New, $paid->fresh()->status);
    }

    public function test_in_flight_ticket_failure_is_not_requeued_after_order_cancellation(): void
    {
        $order = $this->ticket();
        $station = PrintStation::create(['name' => 'In flight station', 'enabled' => true,
            'token_hash' => hash('sha256', 'in-flight'), 'printer_map' => ['kitchen' => 'Printer']]);
        $job = PrintJob::create(['idempotency_key' => 'in-flight-cancel', 'type' => 'kot', 'printer_key' => 'kitchen',
            'source_type' => Order::class, 'source_id' => $order->id, 'status' => 'processing',
            'print_station_id' => $station->id, 'locked_until' => now()->addMinutes(2),
            'payload_format' => 'escpos_base64', 'payload' => base64_encode('test')]);
        $this->cancel($order)->assertOk();
        $job->update(['locked_until' => now()->subMinute()]);
        $this->assertSame(0, PrintJob::availableForStation($station)->count());
        $job->markFailed($station, 'Printer offline');
        $this->assertSame('cancelled', $job->fresh()->status);
        $this->assertFalse($job->retry(auth()->user()));
    }

    public function test_preview_write_failure_rolls_back_cancellation(): void
    {
        $order = $this->ticket();
        $this->ticket();
        $billId = BillHelper::createTableBill($order->table_id);
        Bill::updating(function () { throw new \RuntimeException('Simulated preview failure'); });
        try {
            $this->cancel($order)->assertStatus(500);
            $this->assertSame(OrderStatus::New, $order->fresh()->status);
            $this->assertSame(2, Bill::findOrFail($billId)->orders()->count());
            $this->assertSame('running', $order->table->fresh()->status->value);
        } finally {
            Bill::flushEventListeners();
        }
    }
}

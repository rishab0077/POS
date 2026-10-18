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

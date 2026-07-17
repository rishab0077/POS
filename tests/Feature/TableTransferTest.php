<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TableStatus;
use App\Models\AuditEvent;
use App\Models\Bill;
use App\Models\BillOrder;
use App\Models\Order;
use App\Models\Table;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TableTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_transfer_running_table_with_open_bill(): void
    {
        $this->seed(DatabaseSeeder::class);

        $manager = User::where('category_id', 1)->firstOrFail();
        $source = Table::where('name', 'T1')->firstOrFail();
        $target = Table::where('name', 'T3')->firstOrFail();

        $source->update([
            'status' => TableStatus::Running,
            'taken_at' => now()->subMinutes(15),
        ]);
        $target->update(['status' => TableStatus::Available]);

        $order = Order::create([
            'KOT' => 'TRANSFER-SMOKE',
            'total' => 345,
            'table_id' => $source->id,
            'source_table_id' => $source->id,
            'status' => OrderStatus::Served,
            'order_type' => OrderType::DineIn,
            'waiter_id' => $manager->id,
        ]);

        $bill = Bill::create([
            'bill_id' => 2026063001,
            'table_id' => $source->id,
            'bill_amount' => 345,
            'discount' => 0,
            'taxable_amount' => 0,
            'vat_amount' => 0,
            'service_charge_amount' => 0,
            'grand_total' => 345,
            'status' => 'open',
        ]);

        BillOrder::create([
            'bill_id' => $bill->id,
            'order_id' => $order->id,
        ]);

        $this->actingAs($manager)
            ->withSession([
                'auth.mfa_passed' => true,
                'auth.step_up_at' => time(),
            ])
            ->postJson(route('pos.table.transfer'), [
                'source_table_id' => $source->id,
                'target_table_id' => $target->id,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('message', 'Table transferred successfully.');

        $this->assertSame(TableStatus::Available, $source->fresh()->status);
        $this->assertSame(TableStatus::Running, $target->fresh()->status);
        $this->assertSame($target->id, $order->fresh()->table_id);
        $this->assertSame($source->id, $order->fresh()->source_table_id);
        $this->assertSame($target->id, $bill->fresh()->table_id);
        $this->assertSame($source->id, $bill->fresh()->source_table_id);

        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'table_transfer',
            'category' => 'billing',
            'subject_type' => Table::class,
            'subject_id' => $target->id,
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Menu;
use App\Models\PrintJob;
use App\Models\PrintStation;
use App\Models\Table;
use App\Models\User;
use App\Services\PrintPayloadService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BillingHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_finalizing_a_bill_does_not_claim_physical_print_success(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('category_id', 1)->firstOrFail();
        $table = Table::where('name', 'T1')->firstOrFail();
        $menu = Menu::where('shortcode', 'mc')->firstOrFail();

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
            ->assertOk();

        $response = $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->postJson(route('pos.table.bill'), [
                'tableId' => $table->id,
                'billAction' => 'final',
                'billingSource' => 'all',
                'paymentType' => 'cash',
            ])
            ->assertOk()
            ->assertJsonPath('tableStatus', 'printed')
            ->assertJsonPath('printerConfigured', false)
            ->assertJsonPath('printerOnline', false);

        $bill = Bill::firstOrFail();

        $response->assertJsonPath('previewUrl', route('pos.bill.preview', $bill->id, false));
        $this->assertNotNull($bill->locked_at);
        $this->assertNull($bill->printed_at);
        $this->assertSame('printed', $table->fresh()->status->value);
        $this->assertDatabaseMissing('print_jobs', [
            'source_type' => Bill::class,
            'source_id' => $bill->id,
        ]);

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->get(route('pos.bill.preview', $bill->id))
            ->assertOk()
            ->assertSee('Tax Invoice')
            ->assertSee($bill->invoice_no);
    }

    public function test_offline_configured_counter_station_keeps_bill_job_pending(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('category_id', 1)->firstOrFail();
        $table = Table::where('name', 'T1')->firstOrFail();
        $menu = Menu::where('shortcode', 'mc')->firstOrFail();

        PrintStation::create([
            'name' => 'Offline reception',
            'token_hash' => PrintStation::hashToken('offline-token'),
            'printer_map' => ['counter' => 'RECEIPT'],
            'enabled' => true,
            'last_seen_at' => null,
        ]);

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
            ->assertOk();

        $response = $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->postJson(route('pos.table.bill'), [
                'tableId' => $table->id,
                'billAction' => 'final',
                'billingSource' => 'all',
                'paymentType' => 'cash',
            ])
            ->assertOk()
            ->assertJsonPath('printerConfigured', true)
            ->assertJsonPath('printerOnline', false);

        $bill = Bill::firstOrFail();

        $response->assertJsonPath('billId', $bill->id);
        $this->assertDatabaseHas('print_jobs', [
            'source_type' => Bill::class,
            'source_id' => $bill->id,
            'printer_key' => 'counter',
            'copy_type' => 'customer',
            'status' => PrintJob::STATUS_PENDING,
        ]);
    }

    public function test_duplicate_receipt_is_marked_and_uses_summed_item_quantity(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('category_id', 1)->firstOrFail();
        $table = Table::where('name', 'T1')->firstOrFail();
        $menu = Menu::where('shortcode', 'mc')->firstOrFail();

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
                        'quantity' => 3,
                    ]],
                ],
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->postJson(route('pos.table.bill'), [
                'tableId' => $table->id,
                'billAction' => 'final',
                'billingSource' => 'all',
                'paymentType' => 'cash',
            ])
            ->assertOk();

        $payload = base64_decode(
            app(PrintPayloadService::class)->billPayload(Bill::firstOrFail()->id, true),
            true
        );

        $this->assertIsString($payload);
        $this->assertStringContainsString('*** DUPLICATE COPY ***', $payload);
        $this->assertStringContainsString('Total Qty :3', $payload);
    }

    public function test_billing_shift_schema_is_removed(): void
    {
        $this->assertFalse(Schema::hasTable('billing_shifts'));
        $this->assertFalse(Schema::hasColumn('bills', 'billing_shift_id'));
    }
}

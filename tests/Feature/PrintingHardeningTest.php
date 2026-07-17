<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\BillOrder;
use App\Models\Category;
use App\Models\Menu;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\PrintJob;
use App\Models\PrintStation;
use App\Models\Table;
use App\Models\User;
use App\Services\PrintJobService;
use App\Services\PrintPayloadService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrintingHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_final_bill_printing_always_queues_customer_and_restaurant_copies(): void
    {
        $bill = $this->billFixture();

        app(PrintJobService::class)->queueBillCopies($bill->id);

        $jobs = PrintJob::where('source_id', $bill->id)->get();

        $this->assertCount(2, $jobs);
        $this->assertEqualsCanonicalizing(['customer', 'restaurant'], $jobs->pluck('copy_type')->all());
        $this->assertDatabaseHas('print_jobs', [
            'idempotency_key' => "bill:{$bill->id}:counter",
            'copy_type' => 'customer',
        ]);
        $this->assertDatabaseHas('print_jobs', [
            'idempotency_key' => "bill:{$bill->id}:counter:restaurant",
            'copy_type' => 'restaurant',
        ]);
        $this->assertStringContainsString(
            'CUSTOMER COPY',
            base64_decode($jobs->firstWhere('copy_type', 'customer')->payload, true)
        );
        $this->assertStringContainsString(
            'RESTAURANT COPY',
            base64_decode($jobs->firstWhere('copy_type', 'restaurant')->payload, true)
        );
    }

    public function test_both_copies_create_distinct_idempotent_customer_and_restaurant_jobs(): void
    {
        $bill = $this->billFixture();
        $service = app(PrintJobService::class);

        $service->queueBillCopies($bill->id, 'both');
        $service->queueBillCopies($bill->id, 'both');

        $this->assertSame(2, PrintJob::where('source_id', $bill->id)->count());
        $this->assertDatabaseHas('print_jobs', [
            'idempotency_key' => "bill:{$bill->id}:counter",
            'copy_type' => 'customer',
        ]);
        $this->assertDatabaseHas('print_jobs', [
            'idempotency_key' => "bill:{$bill->id}:counter:restaurant",
            'copy_type' => 'restaurant',
        ]);

        $restaurantJob = PrintJob::where('copy_type', 'restaurant')->sole();
        $this->assertStringContainsString(
            'RESTAURANT COPY',
            base64_decode($restaurantJob->payload, true)
        );
    }

    public function test_configured_default_both_is_used_when_print_copies_is_omitted(): void
    {
        config()->set('pos.printing.receipt.default_copies', 'both');
        $bill = $this->billFixture();

        app(PrintJobService::class)->queueBillCopies($bill->id);

        $this->assertEqualsCanonicalizing(
            ['customer', 'restaurant'],
            PrintJob::where('source_id', $bill->id)->pluck('copy_type')->all()
        );
    }

    public function test_duplicate_and_summary_jobs_store_copy_metadata_and_labels(): void
    {
        $bill = $this->billFixture();
        $service = app(PrintJobService::class);

        $duplicate = $service->queueBill($bill->id, true);
        $summary = $service->queueSummaryBill($bill->id);

        $this->assertSame('duplicate', $duplicate->copy_type);
        $this->assertSame('summary', $summary->copy_type);
        $this->assertStringContainsString(
            '*** DUPLICATE COPY ***',
            base64_decode($duplicate->payload, true)
        );
        $this->assertStringContainsString(
            'SUMMARY COPY',
            base64_decode($summary->payload, true)
        );
    }

    public function test_category_print_destinations_save_and_default_to_kot(): void
    {
        $kot = Category::create([
            'name' => 'Kitchen Items',
            'print_destination' => 'kot',
            'rank' => 1,
        ]);
        $bot = Category::create([
            'name' => 'Beverages',
            'print_destination' => 'bot',
            'rank' => 2,
        ]);
        $default = Category::create([
            'name' => 'Default Items',
            'rank' => 3,
        ]);

        $this->assertSame('kot', $kot->fresh()->print_destination);
        $this->assertSame('bot', $bot->fresh()->print_destination);
        $this->assertSame('kot', $default->fresh()->print_destination);
    }

    public function test_only_customer_or_legacy_customer_acknowledgement_updates_bill_printed_at(): void
    {
        $token = 'ps_copy_test';
        $station = PrintStation::create([
            'name' => 'Reception',
            'token_hash' => PrintStation::hashToken($token),
            'enabled' => true,
            'printer_map' => ['counter' => 'RECEIPT'],
        ]);
        $bill = $this->billFixture();
        $service = app(PrintJobService::class);
        $restaurant = $service->queueBill($bill->id, false, 'counter', 'restaurant');
        $customer = $service->queueBill($bill->id);

        $restaurant->update([
            'status' => PrintJob::STATUS_PROCESSING,
            'print_station_id' => $station->id,
            'locked_until' => now()->addMinute(),
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/print-station/jobs/{$restaurant->id}/printed")
            ->assertOk();

        $this->assertNull($bill->fresh()->printed_at);

        $customer->update([
            'status' => PrintJob::STATUS_PROCESSING,
            'print_station_id' => $station->id,
            'locked_until' => now()->addMinute(),
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/print-station/jobs/{$customer->id}/printed")
            ->assertOk();

        $this->assertNotNull($bill->fresh()->printed_at);
    }

    public function test_legacy_null_copy_type_behaves_as_customer(): void
    {
        $job = new PrintJob(['copy_type' => null]);

        $this->assertSame('customer', $job->effectiveCopyType());
    }

    public function test_receipt_contains_required_operational_fields_and_configured_footer(): void
    {
        config()->set('pos.printing.receipt.footer_text', 'Please visit again');
        config()->set('pos.printing.receipt.width', '80mm');
        $bill = $this->billFixture();

        $receipt = base64_decode(
            app(PrintPayloadService::class)->billPayload($bill->id),
            true
        );

        $this->assertIsString($receipt);

        foreach ([
            'CUSTOMER COPY',
            'Invoice Number: FY82-0001',
            'Fiscal Year: 2082/83',
            'Bill Date:',
            'Bill Time:',
            'Table: T2',
            'Source Table: T1',
            'Order Type: Dine-in',
            'Waiter: Admin',
            'Cashier: Admin',
            'Buyer Name: Test Buyer',
            'Buyer PAN: 123456789',
            'Item',
            'Qty',
            'Unit',
            'Total',
            'Subtotal',
            'Discount',
            'Service Charge',
            'Taxable Amount',
            'VAT 13.00%',
            'Grand Total',
            'Payment Method: Cash',
            'Please visit again',
            'Thank You',
            str_repeat('-', 45),
        ] as $expected) {
            $this->assertStringContainsString($expected, $receipt);
        }
    }

    public function test_58mm_receipt_uses_32_character_separator(): void
    {
        config()->set('pos.printing.receipt.width', '58mm');
        $bill = $this->billFixture();

        $receipt = base64_decode(
            app(PrintPayloadService::class)->billPayload($bill->id),
            true
        );

        $this->assertStringContainsString(str_repeat('-', 32), $receipt);
        $this->assertStringNotContainsString(str_repeat('-', 45), $receipt);
    }

    public function test_kot_and_bot_output_contains_operational_details_without_prices(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('category_id', 1)->firstOrFail();
        $sourceTable = Table::where('name', 'T1')->firstOrFail();
        $table = Table::where('name', 'T2')->firstOrFail();
        $menu = Menu::where('shortcode', 'mc')->firstOrFail();
        $menu->category()->firstOrFail()->update(['print_destination' => 'kot']);

        $order = Order::create([
            'KOT' => 'KOT-PHASE-2C',
            'total' => 500,
            'table_id' => $table->id,
            'source_table_id' => $sourceTable->id,
            'status' => 'new',
            'special_instructions' => 'No onion, extra spicy',
            'order_type' => 'dine_in',
            'waiter_id' => $admin->id,
        ]);
        OrderDetail::create([
            'order_id' => $order->id,
            'menu_id' => $menu->id,
            'quantity' => 2,
            'unit_price' => 250,
        ]);

        $kot = base64_decode(
            app(PrintPayloadService::class)->kotPayload($order->KOT, null, 'kitchen', 'KOT'),
            true
        );

        foreach ([
            'KOT',
            'ID: KOT-PHASE-2C',
            'Printer Area: KITCHEN',
            'Table: T2',
            'Source Table: T1',
            'Order Type: Dine-in',
            'Waiter: Admin',
            'Order Time:',
            'SPECIAL INSTRUCTIONS',
            'No onion, extra spicy',
            $menu->name,
            'Total Qty: 2',
        ] as $expected) {
            $this->assertStringContainsString($expected, $kot);
        }
        $this->assertStringNotContainsString('250.00', $kot);
        $this->assertStringNotContainsString('Price', $kot);
        $this->assertStringNotContainsString('Unit', $kot);

        $menu->category()->firstOrFail()->update(['print_destination' => 'bot']);
        $order->update([
            'table_id' => null,
            'order_type' => 'takeaway',
        ]);

        $bot = base64_decode(
            app(PrintPayloadService::class)->kotPayload($order->KOT, null, 'bar', 'BOT'),
            true
        );

        $this->assertStringContainsString('BOT', $bot);
        $this->assertStringContainsString('Printer Area: BAR', $bot);
        $this->assertStringContainsString('Table: Takeaway', $bot);
        $this->assertStringContainsString('Order Type: Takeaway / Packed', $bot);
        $this->assertStringContainsString('Total Qty: 2', $bot);
        $this->assertStringNotContainsString('250.00', $bot);
    }

    public function test_mixed_order_creates_separate_filtered_kot_and_bot_jobs_without_extra_preparation_copies(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('category_id', 1)->firstOrFail();
        $table = Table::where('name', 'T1')->firstOrFail();
        $food = Menu::where('shortcode', 'mc')->firstOrFail();
        $beverage = Menu::where('shortcode', 'b')->firstOrFail();

        $food->category()->firstOrFail()->update(['print_destination' => 'kot']);
        $beverage->category()->firstOrFail()->update(['print_destination' => 'bot']);

        PrintStation::create([
            'name' => 'Kitchen and bar',
            'token_hash' => PrintStation::hashToken('ps_kitchen_bar'),
            'enabled' => true,
            'printer_map' => [
                'kitchen' => 'KITCHEN_KOT',
                'bar' => 'BAR_BOT',
                'counter' => 'COUNTER',
            ],
        ]);

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->postJson(route('order.submit'), [
                'source' => 'pos',
                'tableId' => $table->id,
                'specialInstructions' => [],
                'isPickUpOrder' => 'false',
                'paymentMethod' => 'cash',
                'billTable' => 'true',
                'order' => [
                    'orderItems' => [
                        [
                            'id' => $food->id,
                            'quantity' => 1,
                        ],
                        [
                            'id' => $beverage->id,
                            'quantity' => 2,
                        ],
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertSame(1, PrintJob::where('type', 'kot')->count());
        $this->assertSame(1, PrintJob::where('type', 'bot')->count());
        $this->assertSame(2, PrintJob::where('type', 'bill')->count());

        $kot = base64_decode(PrintJob::where('type', 'kot')->sole()->payload, true);
        $bot = base64_decode(PrintJob::where('type', 'bot')->sole()->payload, true);

        $this->assertStringContainsString('KOT', $kot);
        $this->assertStringContainsString($food->name, $kot);
        $this->assertStringNotContainsString($beverage->name, $kot);

        $this->assertStringContainsString('BOT', $bot);
        $this->assertStringContainsString($beverage->name, $bot);
        $this->assertStringNotContainsString($food->name, $bot);

        $this->assertEqualsCanonicalizing(
            ['customer', 'restaurant'],
            PrintJob::where('type', 'bill')->pluck('copy_type')->all()
        );
    }

    private function billFixture(): Bill
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('category_id', 1)->firstOrFail();
        $sourceTable = Table::where('name', 'T1')->firstOrFail();
        $table = Table::where('name', 'T2')->firstOrFail();
        $menu = Menu::where('shortcode', 'mc')->firstOrFail();

        $order = Order::create([
            'KOT' => 'KOT-' . uniqid(),
            'total' => 500,
            'table_id' => $table->id,
            'source_table_id' => $sourceTable->id,
            'status' => 'served',
            'order_type' => 'dine_in',
            'waiter_id' => $admin->id,
        ]);
        OrderDetail::create([
            'order_id' => $order->id,
            'menu_id' => $menu->id,
            'quantity' => 2,
            'unit_price' => 250,
        ]);

        $bill = Bill::create([
            'bill_id' => 20260626001,
            'invoice_no' => 'FY82-0001',
            'fiscal_year' => '2082/83',
            'buyer_name' => 'Test Buyer',
            'buyer_pan' => '123456789',
            'table_id' => $table->id,
            'source_table_id' => $sourceTable->id,
            'bill_amount' => 500,
            'discount' => 20,
            'service_charge_amount' => 10,
            'taxable_amount' => 490,
            'vat_amount' => 63.70,
            'grand_total' => 553.70,
            'status' => 'open',
            'payment_method' => 'cash',
            'locked_at' => now(),
            'locked_by' => $admin->id,
        ]);
        BillOrder::create([
            'bill_id' => $bill->id,
            'order_id' => $order->id,
        ]);

        return $bill;
    }
}

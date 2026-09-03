<?php

namespace Tests\Feature;

use App\Http\Service\ReportingService;
use App\Models\Bill;
use App\Models\Menu;
use App\Models\Table;
use App\Models\User;
use App\Services\PrintPayloadService;
use App\Services\VatCalculatorService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SplitPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_bill_can_be_split_and_is_reported_and_printed_by_actual_method(): void
    {
        [$admin, $table, $menu] = $this->seedOrder();
        $totalCents = $this->totalCents($menu);
        $cashCents = intdiv($totalCents, 2);
        $fonepayCents = $totalCents - $cashCents;

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->postJson(route('pos.table.bill'), [
                'tableId' => $table->id,
                'billAction' => 'final',
                'billingSource' => 'all',
                'paymentType' => 'split',
                'payments' => [
                    ['method' => 'cash', 'amount' => $this->money($cashCents)],
                    ['method' => 'fonepay', 'amount' => $this->money($fonepayCents), 'reference_no' => 'FP-123'],
                ],
            ])
            ->assertOk();

        $bill = Bill::with('payments', 'fiscalSnapshot')->firstOrFail();

        $this->assertSame('split', $bill->payment_method);
        $this->assertCount(2, $bill->payments);
        $this->assertSame($this->money($totalCents), number_format((float) $bill->payments->sum('amount'), 2, '.', ''));
        $this->assertSame('FP-123', $bill->payments->firstWhere('payment_method', 'fonepay')->reference_no);
        $this->assertCount(2, $bill->fiscalSnapshot->payment_breakdown);

        $summary = app(ReportingService::class)->dailyPaymentSummary(now()->toDateString());
        $breakdown = collect($summary['breakdown']);
        $this->assertSame((float) ($cashCents / 100), (float) $breakdown->firstWhere('method', 'cash')['direct_sales']);
        $this->assertSame((float) ($fonepayCents / 100), (float) $breakdown->firstWhere('method', 'fonepay')['direct_sales']);

        $print = base64_decode(app(PrintPayloadService::class)->billPayload($bill->id), true);
        $this->assertStringContainsString('Cash', $print);
        $this->assertStringContainsString('Fonepay', $print);
        $this->assertStringContainsString('FP-123', $print);
    }

    public function test_invalid_split_requests_do_not_finalize_or_create_allocations(): void
    {
        [$admin, $table, $menu] = $this->seedOrder();
        $totalCents = $this->totalCents($menu);

        foreach ([
            [
                ['method' => 'cash', 'amount' => $this->money($totalCents - 2)],
                ['method' => 'fonepay', 'amount' => '0.01'],
            ],
            [
                ['method' => 'cash', 'amount' => $this->money(intdiv($totalCents, 2))],
                ['method' => 'cash', 'amount' => $this->money($totalCents - intdiv($totalCents, 2))],
            ],
            [
                ['method' => 'credit', 'amount' => $this->money(intdiv($totalCents, 2))],
                ['method' => 'cash', 'amount' => $this->money($totalCents - intdiv($totalCents, 2))],
            ],
        ] as $payments) {
            $this->actingAs($admin)
                ->withSession(['auth.mfa_passed' => true])
                ->postJson(route('pos.table.bill'), [
                    'tableId' => $table->id,
                    'billAction' => 'final',
                    'billingSource' => 'all',
                    'paymentType' => 'split',
                    'payments' => $payments,
                ])
                ->assertUnprocessable();

            $bill = Bill::firstOrFail();
            $this->assertNull($bill->fresh()->locked_at);
            $this->assertDatabaseCount('bill_payments', 0);
            $this->assertDatabaseCount('fiscal_invoice_snapshots', 0);
        }
    }

    public function test_takeaway_split_and_existing_single_payment_both_create_allocations(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('category_id', 1)->firstOrFail();
        $menu = Menu::where('shortcode', 'mc')->firstOrFail();
        $totalCents = $this->totalCents($menu);
        $cashCents = 1;

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->postJson(route('order.submit'), [
                'source' => 'pos',
                'tableId' => null,
                'specialInstructions' => [],
                'isPickUpOrder' => 'true',
                'paymentMethod' => 'split',
                'payments' => [
                    ['method' => 'cash', 'amount' => $this->money($cashCents)],
                    ['method' => 'card', 'amount' => $this->money($totalCents - $cashCents)],
                ],
                'billTable' => 'true',
                'order' => ['orderItems' => [['id' => $menu->id, 'quantity' => 1]]],
            ])
            ->assertOk();

        $this->assertDatabaseCount('bill_payments', 2);

        [$admin, $table] = $this->seedOrder(false);
        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->postJson(route('pos.table.bill'), [
                'tableId' => $table->id,
                'billAction' => 'final',
                'paymentType' => 'cash',
            ])
            ->assertOk();

        $this->assertDatabaseCount('bill_payments', 3);
    }

    public function test_order_screen_uses_existing_table_total_and_keeps_split_controls_scrollable(): void
    {
        [$admin, $table, $menu] = $this->seedOrder();

        $this->actingAs($admin)
            ->get(route('pos.main', ['tableId' => $table->id]))
            ->assertOk()
            ->assertSee('Existing orders')
            ->assertSee(number_format((float) $menu->price, 2))
            ->assertSee('max-h-[46vh]', false)
            ->assertSee('lg:flex-row', false)
            ->assertSee('NPR', false)
            ->assertSee('id="addNotesModal"', false)
            ->assertSee('max-h-[calc(100vh-1rem)]', false)
            ->assertSee('existingTableOrderTotal', false);

        $this->actingAs($admin)
            ->get(route('pos.tables'))
            ->assertOk()
            ->assertSee('max-h-[calc(100vh-1rem)]', false)
            ->assertSee('overflow-y-auto', false)
            ->assertSee('aria-label="Close table"', false);

        $script = file_get_contents(resource_path('js/pos.js'));
        $this->assertStringContainsString('updateSplitRemainder(true)', $script);
        $this->assertStringContainsString('updateSplitRemainder(false)', $script);
        $this->assertStringContainsString('syncSplitMethodOptions', $script);
        $this->assertStringContainsString('First split amount must be less than the bill total', $script);
        $this->assertStringContainsString('selectedNotes.length = 0', $script);
        $this->assertStringContainsString('addNotesModal").style.display = "flex"', $script);
        $this->assertStringContainsString('if (hasPrevOrders)', $script);

        $this->actingAs($admin)
            ->get(route('admin.bills.index'))
            ->assertOk()
            ->assertSee('min-w-[40rem]', false)
            ->assertSee('overflow-x-auto', false);
    }

    private function seedOrder(bool $seed = true): array
    {
        if ($seed) {
            $this->seed(DatabaseSeeder::class);
        }

        $admin = User::where('category_id', 1)->firstOrFail();
        $table = Table::where('status', 'available')->firstOrFail();
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
                'order' => ['orderItems' => [['id' => $menu->id, 'quantity' => 1]]],
            ])
            ->assertOk();

        return [$admin, $table, $menu];
    }

    private function totalCents(Menu $menu): int
    {
        $total = app(VatCalculatorService::class)->calculate((float) $menu->price)['grand_total'];

        return (int) round($total * 100);
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}

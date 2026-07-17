<?php

namespace Tests\Feature;

use App\Helpers\BillHelper;
use App\Models\Bill;
use App\Models\CbmsSubmission;
use App\Models\Menu;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use App\Services\BusinessConfigurationService;
use App\Services\VatCalculatorService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class FiscalInvoiceIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_finalization_captures_an_immutable_fiscal_snapshot(): void
    {
        [$bill, $menu] = $this->finalizeOneItem();
        $snapshot = $bill->fiscalSnapshot()->with('items')->firstOrFail();
        $originalItemName = $menu->name;
        $originalSellerName = $snapshot->seller_name;

        $this->assertSame($bill->invoice_no, $snapshot->invoice_no);
        $this->assertSame($bill->fiscal_year, $snapshot->fiscal_year);
        $this->assertSame('standard', $snapshot->items->first()->tax_category);
        $this->assertSame(64, strlen($snapshot->document_hash));
        $this->assertSame('pending', CbmsSubmission::where('fiscal_invoice_snapshot_id', $snapshot->id)->value('status'));
        $this->assertSame(
            round((float) $snapshot->taxable_sales + (float) $snapshot->vat, 2),
            round((float) $snapshot->total_sales, 2)
        );

        $menu->update(['name' => 'Changed after sale']);
        Restaurant::query()->firstOrFail()->update(['name' => 'Changed Restaurant']);

        $this->assertTrue(BillHelper::getBillOrders($bill->id)->has($originalItemName));
        $this->assertSame(
            $originalSellerName,
            app(BusinessConfigurationService::class)->detailsForBill($bill->fresh())['name']
        );

        $this->expectException(LogicException::class);
        $snapshot->update(['seller_name' => 'Tampered']);
    }

    public function test_vat_calculation_reconciles_to_the_cent(): void
    {
        config()->set('pos.tax.vat_rate', 13);
        config()->set('pos.tax.vat_inclusive', true);
        config()->set('pos.tax.service_charge_enabled', false);

        $tax = app(VatCalculatorService::class)->calculate(999.99, 33.33);

        $this->assertSame(966.66, $tax['grand_total']);
        $this->assertSame(
            (int) round($tax['grand_total'] * 100),
            (int) round(($tax['taxable_amount'] + $tax['vat_amount']) * 100)
        );
    }

    private function finalizeOneItem(): array
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

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->postJson(route('pos.table.bill'), [
                'tableId' => $table->id,
                'billAction' => 'final',
                'billingSource' => 'all',
                'paymentType' => 'cash',
            ])
            ->assertOk();

        return [Bill::with('fiscalSnapshot')->firstOrFail(), $menu];
    }
}

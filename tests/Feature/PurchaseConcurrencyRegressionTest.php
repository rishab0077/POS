<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\PurchaseInvoice;
use App\Modules\Inventory\Models\StockCategory;
use App\Modules\Inventory\Models\StockItem;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\PurchasePostingService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseConcurrencyRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(): PurchaseInvoice
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('category_id', 1)->firstOrFail())
            ->withSession(['auth.mfa_passed' => true]);
        $stock = StockItem::create([
            'category_id' => StockCategory::firstOrFail()->id, 'name' => 'Regression stock',
            'unit' => 'kg', 'current_quantity' => 10, 'active' => true,
        ]);
        $invoice = PurchaseInvoice::create([
            'supplier_id' => Supplier::create(['name' => 'Regression supplier'])->id,
            'invoice_no' => 'REGRESSION-1', 'bill_date' => now(), 'status' => 'draft',
            'subtotal' => 100, 'taxable_amount' => 100, 'total_amount' => 100,
            'paid_amount' => 0, 'balance_amount' => 100,
        ]);
        $invoice->items()->create([
            'stock_item_id' => $stock->id, 'item_name' => $stock->name, 'unit' => 'kg',
            'quantity' => 2, 'unit_price' => 50, 'subtotal' => 100,
            'taxable_amount' => 100, 'total_amount' => 100,
        ]);
        return $invoice;
    }

    public function test_two_stale_draft_instances_cannot_post_stock_twice(): void
    {
        $invoice = $this->invoice();
        $stale = PurchaseInvoice::findOrFail($invoice->id);
        $service = app(PurchasePostingService::class);
        $service->post($invoice);
        $service->post($stale);
        $this->assertSame(12.0, (float) $invoice->items->first()->stockItem->fresh()->current_quantity);
        $this->assertSame(1, StockMovement::where('reference_type', 'purchase_invoice')->count());
    }

    public function test_two_stale_posted_instances_cannot_void_stock_twice(): void
    {
        $invoice = $this->invoice();
        $service = app(PurchasePostingService::class);
        $invoice = $service->post($invoice);
        $stale = PurchaseInvoice::findOrFail($invoice->id);
        $service->void($invoice, 'Wrong purchase');
        try {
            $service->void($stale, 'Repeated void');
            $this->fail('Repeated void must fail');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('invoice', $e->errors());
        }
        $this->assertSame(10.0, (float) $invoice->items->first()->stockItem->fresh()->current_quantity);
        $this->assertSame(1, StockMovement::where('reference_type', 'purchase_void')->count());
    }

    public function test_supplier_payments_require_posted_invoice_and_cannot_overpay(): void
    {
        $invoice = $this->invoice();
        $payload = [
            'supplier_id' => $invoice->supplier_id, 'purchase_invoice_id' => $invoice->id,
            'payment_date' => now()->toDateString(), 'amount' => 100, 'payment_method' => 'cash',
        ];
        $this->postJson(route('inventory.supplier-payments.store'), $payload)->assertUnprocessable();
        $this->assertDatabaseCount('supplier_payments', 0);
        app(PurchasePostingService::class)->post($invoice);
        $this->postJson(route('inventory.supplier-payments.store'), $payload)->assertRedirect();
        $this->postJson(route('inventory.supplier-payments.store'), $payload)->assertUnprocessable();
        $this->assertSame(100.0, (float) $invoice->payments()->sum('amount'));
    }
}

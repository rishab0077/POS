<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Bill;
use App\Models\CbmsSubmission;
use App\Models\Menu;
use App\Models\Table;
use App\Models\User;
use App\Modules\Inventory\Models\MenuItemStockMapping;
use App\Modules\Inventory\Models\StockCategory;
use App\Modules\Inventory\Models\StockItem;
use App\Services\CbmsService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReturnedInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_default_to_waste_and_reusable_stock_can_be_restored_once(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('category_id', UserRole::Admin->value)->firstOrFail();
        $menu = Menu::where('shortcode', 'mc')->firstOrFail();
        $stock = StockItem::create([
            'category_id' => StockCategory::firstOrFail()->id,
            'name' => 'Test ingredient',
            'unit' => 'kg',
            'current_quantity' => 10,
            'low_stock_threshold' => 1,
            'auto_deduct' => true,
            'active' => true,
        ]);
        MenuItemStockMapping::create([
            'menu_item_id' => $menu->id,
            'stock_item_id' => $stock->id,
            'quantity_per_sale' => 0.5,
            'active' => true,
        ]);

        $this->actingAs($admin)->withSession(['auth.mfa_passed' => true])
            ->postJson(route('order.submit'), [
                'source' => 'pos',
                'tableId' => Table::where('name', 'T1')->value('id'),
                'specialInstructions' => [],
                'isPickUpOrder' => 'false',
                'paymentMethod' => 'cash',
                'billTable' => 'false',
                'order' => ['orderItems' => [['id' => $menu->id, 'quantity' => 2]]],
            ])->assertOk();

        $this->actingAs($admin)->withSession(['auth.mfa_passed' => true])
            ->postJson(route('pos.table.bill'), [
                'tableId' => Table::where('name', 'T1')->value('id'),
                'billAction' => 'final',
                'billingSource' => 'all',
                'paymentType' => 'cash',
            ])->assertOk();

        $bill = Bill::with('fiscalSnapshot.items')->firstOrFail();
        $invoiceItem = $bill->fiscalSnapshot->items->first();
        $this->assertSame(9.0, (float) $stock->fresh()->current_quantity);
        $this->assertSame(1.0, (float) $invoiceItem->inventory_consumption[0]['quantity']);

        CbmsSubmission::where('fiscal_invoice_snapshot_id', $bill->fiscalSnapshot->id)
            ->update(['status' => 'submitted']);
        $creditNote = app(CbmsService::class)->issueCreditNote(
            $bill->fiscalSnapshot->fresh('cbmsSubmission'),
            'Reusable item returned',
            'cash',
            $admin,
            [$invoiceItem->id => 1]
        );
        $returnedItem = $creditNote->items()->firstOrFail();

        $this->assertNull($returnedItem->inventory_restored_at);
        $this->assertSame(0.5, (float) $returnedItem->inventory_restore_quantities[0]['quantity']);
        $this->assertSame(9.0, (float) $stock->fresh()->current_quantity);

        $this->actingAs($admin)
            ->post(route('inventory.returned-items.restore', $returnedItem))
            ->assertRedirect(route('inventory.stock-movements.index'));

        $this->assertSame(9.5, (float) $stock->fresh()->current_quantity);
        $this->assertNotNull($returnedItem->fresh()->inventory_restored_at);
        $this->assertDatabaseHas('stock_movements', [
            'stock_item_id' => $stock->id,
            'movement_type' => 'in',
            'reference_type' => 'fiscal_credit_note_item',
            'reference_id' => $returnedItem->id,
            'reason' => 'return_restore',
        ]);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'returned_item_inventory_restored']);

        $this->from(route('inventory.stock-movements.index'))
            ->actingAs($admin)
            ->post(route('inventory.returned-items.restore', $returnedItem))
            ->assertSessionHasErrors('inventory');
        $this->assertSame(9.5, (float) $stock->fresh()->current_quantity);
    }
}

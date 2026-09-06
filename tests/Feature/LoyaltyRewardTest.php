<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Service\ReportingService;
use App\Models\AuditEvent;
use App\Models\Bill;
use App\Models\Category;
use App\Models\Menu;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Table;
use App\Models\User;
use App\Modules\Inventory\Models\MenuItemStockMapping;
use App\Modules\Inventory\Models\StockCategory;
use App\Modules\Inventory\Models\StockItem;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyRewardTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_can_redeem_one_eligible_unit_and_receipt_keeps_it_at_zero(): void
    {
        [$cashier, $menu] = $this->loyaltyFixture();
        $stock = StockItem::create([
            'category_id' => StockCategory::firstOrFail()->id,
            'name' => 'Loyalty test ingredient',
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

        $this->actingAs($cashier)
            ->withSession(['auth.mfa_passed' => true])
            ->postJson(route('order.submit'), $this->orderPayload($menu, 2, 1, true))
            ->assertOk();

        $order = Order::with('orderDetails')->firstOrFail();
        $detail = $order->orderDetails->first();
        $bill = Bill::with('fiscalSnapshot.items')->firstOrFail();
        $reward = $bill->fiscalSnapshot->items->firstWhere('pricing_reason', 'physical_stamp_card');
        $paid = $bill->fiscalSnapshot->items->firstWhere('pricing_reason', null);

        $this->assertSame((float) $menu->price, (float) $order->total);
        $this->assertSame(1, (int) $detail->loyalty_reward_quantity);
        $this->assertSame($cashier->id, $detail->loyalty_redeemed_by);
        $this->assertNotNull($reward);
        $this->assertSame(0.0, (float) $reward->unit_price);
        $this->assertSame(0.0, (float) $reward->line_total);
        $this->assertSame((float) $menu->price, (float) $reward->original_unit_price);
        $this->assertSame($cashier->name, $reward->approved_by_name);
        $this->assertSame((float) $bill->bill_amount, (float) $bill->fiscalSnapshot->items->sum('line_total'));
        $this->assertSame(9.0, (float) $stock->fresh()->current_quantity);
        $this->assertSame(0.5, (float) $paid->inventory_consumption[0]['quantity']);
        $this->assertSame(0.5, (float) $reward->inventory_consumption[0]['quantity']);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'loyalty_reward_applied']);

        $this->get(route('pos.bill.preview', $bill))->assertOk()
            ->assertSee('Loyalty Reward')
            ->assertSee('Physical stamp-card reward');
    }

    public function test_server_rejects_a_reward_for_an_ineligible_item(): void
    {
        $this->seed(DatabaseSeeder::class);
        $cashier = User::where('category_id', UserRole::Admin->value)->firstOrFail();
        $menu = Menu::firstOrFail();
        $menu->category()->update(['loyalty_eligible' => false]);

        $this->actingAs($cashier)
            ->withSession(['auth.mfa_passed' => true])
            ->postJson(route('order.submit'), $this->orderPayload($menu, 1, 1, true))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order.orderItems');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_cashier_can_apply_and_remove_rewards_from_an_existing_table_order(): void
    {
        [$cashier, $menu] = $this->loyaltyFixture();
        $table = Table::where('name', 'T1')->firstOrFail();

        $this->actingAs($cashier)
            ->withSession(['auth.mfa_passed' => true])
            ->postJson(route('order.submit'), $this->orderPayload($menu, 2, 0, false, $table))
            ->assertOk();

        $detail = OrderDetail::firstOrFail();
        $this->postJson(route('pos.loyalty.update', $detail), ['quantity' => 1])
            ->assertOk()
            ->assertJsonPath('quantity', 1);
        $this->assertSame((float) $menu->price, (float) $detail->order->fresh()->total);

        $this->postJson(route('pos.loyalty.update', $detail), ['quantity' => 0])
            ->assertOk()
            ->assertJsonPath('quantity', 0);
        $this->assertSame((float) $menu->price * 2, (float) $detail->order->fresh()->total);
        $this->assertSame(1, AuditEvent::where('event_type', 'loyalty_reward_removed')->count());
    }

    public function test_finalized_loyalty_reward_is_reported_with_original_value(): void
    {
        [$cashier, $menu] = $this->loyaltyFixture();

        $this->actingAs($cashier)
            ->withSession(['auth.mfa_passed' => true])
            ->postJson(route('order.submit'), $this->orderPayload($menu, 1, 1, true))
            ->assertOk();

        $report = app(ReportingService::class)->discountReport(now()->toDateString(), now()->toDateString());

        $this->assertSame(1.0, $report['totals']['loyalty_quantity']);
        $this->assertSame((float) $menu->price, $report['totals']['loyalty_value']);
        $this->assertSame($cashier->name, $report['loyalty_redemptions']->first()->approved_by_name);
    }

    private function loyaltyFixture(): array
    {
        $this->seed(DatabaseSeeder::class);
        $cashier = User::where('category_id', UserRole::Admin->value)->firstOrFail();
        $menu = Menu::where('shortcode', 'mc')->firstOrFail();
        $menu->category()->firstOrFail()->update(['loyalty_eligible' => true]);

        return [$cashier, $menu];
    }

    private function orderPayload(Menu $menu, int $quantity, int $rewardQuantity, bool $takeaway, ?Table $table = null): array
    {
        return [
            'source' => 'pos',
            'tableId' => $table?->id,
            'specialInstructions' => [],
            'isPickUpOrder' => $takeaway ? 'true' : 'false',
            'paymentMethod' => 'cash',
            'billTable' => 'false',
            'order' => [
                'orderItems' => [[
                    'id' => $menu->id,
                    'quantity' => $quantity,
                    'loyalty_reward_quantity' => $rewardQuantity,
                ]],
            ],
        ];
    }
}

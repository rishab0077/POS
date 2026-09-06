<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Service\ReportingService;
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
            ->postJson(route('order.submit'), $this->orderPayload($menu, 2, true, null, 1))
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
            ->postJson(route('order.submit'), $this->orderPayload($menu, 1, true, null, 1))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('loyalty_rewards');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_table_order_is_not_redeemed_until_cashier_finalizes_payment(): void
    {
        [$cashier, $menu] = $this->loyaltyFixture();
        $table = Table::where('name', 'T1')->firstOrFail();

        $this->actingAs($cashier)
            ->withSession(['auth.mfa_passed' => true])
            ->postJson(route('order.submit'), $this->orderPayload($menu, 2, false, $table))
            ->assertOk();

        $detail = OrderDetail::firstOrFail();
        $this->assertSame(0, (int) $detail->loyalty_reward_quantity);
        $this->assertSame((float) $menu->price * 2, (float) $detail->order->fresh()->total);
        $this->assertDatabaseCount('bills', 0);

        $this->postJson(route('pos.table.bill'), [
            'tableId' => $table->id,
            'billAction' => 'final',
            'billingSource' => 'all',
            'paymentType' => 'cash',
            'loyalty_rewards' => [['menu_id' => $menu->id, 'quantity' => 1]],
        ])->assertOk();

        $this->assertSame(1, (int) $detail->fresh()->loyalty_reward_quantity);
        $this->assertSame((float) $menu->price, (float) $detail->order->fresh()->total);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'loyalty_reward_applied']);
    }

    public function test_loyalty_cannot_be_applied_when_only_sending_an_order_to_kitchen(): void
    {
        [$cashier, $menu] = $this->loyaltyFixture();
        $table = Table::where('name', 'T1')->firstOrFail();
        $payload = $this->orderPayload($menu, 1, false, $table);
        $payload['loyalty_rewards'] = [['menu_id' => $menu->id, 'quantity' => 1]];

        $this->actingAs($cashier)
            ->withSession(['auth.mfa_passed' => true])
            ->postJson(route('order.submit'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('loyalty_rewards');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_finalized_loyalty_reward_is_reported_with_original_value(): void
    {
        [$cashier, $menu] = $this->loyaltyFixture();

        $this->actingAs($cashier)
            ->withSession(['auth.mfa_passed' => true])
            ->postJson(route('order.submit'), $this->orderPayload($menu, 1, true, null, 1))
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

    private function orderPayload(Menu $menu, int $quantity, bool $takeaway, ?Table $table = null, int $rewardQuantity = 0): array
    {
        $payload = [
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
                ]],
            ],
        ];

        if ($rewardQuantity > 0) {
            $payload['loyalty_rewards'] = [[
                'menu_id' => $menu->id,
                'quantity' => $rewardQuantity,
            ]];
        }

        return $payload;
    }
}

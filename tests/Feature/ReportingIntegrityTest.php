<?php

namespace Tests\Feature;

use App\Http\Service\ReportingService;
use App\Models\Bill;
use App\Models\Menu;
use App\Models\Table;
use App\Models\User;
use App\Services\CbmsService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportingIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_reports_only_count_finalized_orders_at_sale_time_prices(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('category_id', 1)->firstOrFail();
        $menu = Menu::where('shortcode', 'mc')->firstOrFail();
        $salePrice = (float) $menu->price;
        $firstTable = Table::where('name', 'T1')->firstOrFail();
        $secondTable = Table::where('name', 'T2')->firstOrFail();

        foreach ([$firstTable, $secondTable] as $table) {
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
        }

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->postJson(route('pos.table.bill'), [
                'tableId' => $firstTable->id,
                'billAction' => 'final',
                'billingSource' => 'all',
                'paymentType' => 'cash',
            ])
            ->assertOk();

        $menu->update(['price' => 9999]);

        $report = app(ReportingService::class)
            ->salesByItemReport(now()->startOfDay(), now()->endOfDay());
        $row = collect($report['data'])->firstWhere('menu', $menu->name);

        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->no_of_sales);
        $this->assertSame($salePrice, (float) $row->total_amount);

        $snapshot = Bill::whereNotNull('locked_at')->firstOrFail()->fiscalSnapshot;
        $snapshot->cbmsSubmission->update(['status' => 'submitted']);
        app(CbmsService::class)->issueCreditNote($snapshot, 'Returned', 'cash', $admin);

        $itemReport = app(ReportingService::class)->salesByItemReport(now()->startOfDay(), now()->endOfDay());
        $itemRow = collect($itemReport['data'])->firstWhere('menu', $menu->name);
        $categoryReport = app(ReportingService::class)->salesByCategoryReport(now()->startOfDay(), now()->endOfDay());
        $categoryRow = collect($categoryReport['data'])->firstWhere('category', $menu->category->first()->name);

        $this->assertSame(0.0, (float) $itemRow->no_of_sales);
        $this->assertSame(0.0, (float) $itemRow->total_amount);
        $this->assertSame(0.0, (float) $categoryRow['no_of_sales']);
        $this->assertSame(0.0, (float) $categoryRow['total_amount']);
    }

    public function test_selected_end_date_does_not_include_the_following_calendar_day(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('category_id', 1)->firstOrFail();
        $menu = Menu::where('shortcode', 'mc')->firstOrFail();
        $tables = Table::whereIn('name', ['T1', 'T2'])->orderBy('name')->get();

        foreach ($tables as $table) {
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
        }

        $bills = Bill::orderBy('id')->get();
        $bills[0]->update(['locked_at' => '2026-06-10 12:00:00']);
        $bills[1]->update(['locked_at' => '2026-06-11 00:00:00']);

        $report = app(ReportingService::class)
            ->salesByItemReport('2026-06-10', '2026-06-10');
        $row = collect($report['data'])->firstWhere('menu', $menu->name);

        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->no_of_sales);
    }
}

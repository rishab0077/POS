<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillListingIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_bill_listing_respects_end_date_and_deleted_filters_in_totals(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('category_id', 1)->firstOrFail();
        $activeBill = $this->billAt(20260610001, 100, '2026-06-10 12:00:00');
        $deletedBill = $this->billAt(20260610002, 200, '2026-06-10 13:00:00');
        $nextDayBill = $this->billAt(20260611001, 400, '2026-06-11 00:00:00');
        $deletedBill->delete();

        $baseQuery = [
            'startDate' => '2026-06-10',
            'endDate' => '2026-06-10',
        ];

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->getJson(route('admin.bills.by.date', $baseQuery))
            ->assertOk()
            ->assertJsonPath('totalSales', 100)
            ->assertSee($activeBill->bill_id)
            ->assertDontSee($deletedBill->bill_id)
            ->assertDontSee($nextDayBill->bill_id);

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->getJson(route('admin.bills.by.date', array_merge($baseQuery, [
                'includeDeleted' => 'true',
            ])))
            ->assertOk()
            ->assertJsonPath('totalSales', 300)
            ->assertSee($activeBill->bill_id)
            ->assertSee($deletedBill->bill_id)
            ->assertDontSee($nextDayBill->bill_id);

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->getJson(route('admin.bills.by.date', array_merge($baseQuery, [
                'onlyDeleted' => 'true',
            ])))
            ->assertOk()
            ->assertJsonPath('totalSales', 200)
            ->assertDontSee($activeBill->bill_id)
            ->assertSee($deletedBill->bill_id)
            ->assertDontSee($nextDayBill->bill_id);
    }

    public function test_bill_listing_rejects_invalid_date_ranges(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('category_id', 1)->firstOrFail();

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->getJson(route('admin.bills.by.date', [
                'startDate' => '2026-06-11',
                'endDate' => '2026-06-10',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('endDate');
    }

    private function billAt(int $billId, float $grandTotal, string $createdAt): Bill
    {
        $bill = Bill::create([
            'bill_id' => $billId,
            'bill_amount' => $grandTotal,
            'discount' => 0,
            'taxable_amount' => $grandTotal,
            'vat_amount' => 0,
            'service_charge_amount' => 0,
            'grand_total' => $grandTotal,
            'status' => 'closed',
            'payment_method' => 'cash',
            'locked_at' => $createdAt,
        ]);

        $bill->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        return $bill;
    }
}

<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Service\ReportingService;
use App\Models\Bill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_credit_payment_updates_balance_and_daily_collection(): void
    {
        $manager = User::factory()->create([
            'category_id' => UserRole::Manager,
        ]);

        $bill = Bill::create([
            'bill_id' => 20260618001,
            'invoice_no' => '2082-83-0001',
            'bill_amount' => 1000,
            'discount' => 0,
            'taxable_amount' => 884.96,
            'vat_amount' => 115.04,
            'service_charge_amount' => 0,
            'grand_total' => 1000,
            'payment_method' => 'credit',
            'credit_customer_name' => 'Test Customer',
            'credit_status' => 'open',
            'credit_paid_amount' => 0,
            'status' => 'closed',
            'printed_at' => now()->subDay(),
            'locked_at' => now()->subDay(),
        ]);

        $this
            ->actingAs($manager)
            ->withSession(['auth.mfa_passed' => true, 'auth.step_up_at' => time()])
            ->post(route('reporting.credits.settle', $bill), [
                'amount' => 400,
                'payment_method' => 'esewa',
                'paid_at' => now()->toDateString(),
            ])
            ->assertRedirect();

        $bill->refresh();

        $this->assertSame('partial', $bill->credit_status);
        $this->assertEquals(400.0, (float) $bill->credit_paid_amount);
        $this->assertEquals(600.0, $bill->creditBalance());

        $summary = app(ReportingService::class)->dailyPaymentSummary(now()->toDateString());
        $esewa = collect($summary['breakdown'])->firstWhere('method', 'esewa');

        $this->assertEquals(400.0, $esewa['credit_collections']);
        $this->assertEquals(400.0, $summary['totals']['collected_sales']);
    }

    public function test_credit_payment_cannot_exceed_outstanding_balance(): void
    {
        $manager = User::factory()->create([
            'category_id' => UserRole::Manager,
        ]);

        $bill = Bill::create([
            'bill_id' => 20260618002,
            'bill_amount' => 500,
            'discount' => 0,
            'taxable_amount' => 442.48,
            'vat_amount' => 57.52,
            'service_charge_amount' => 0,
            'grand_total' => 500,
            'payment_method' => 'credit',
            'credit_customer_name' => 'Test Customer',
            'credit_status' => 'open',
            'credit_paid_amount' => 0,
            'status' => 'closed',
            'printed_at' => now(),
            'locked_at' => now(),
        ]);

        $this
            ->actingAs($manager)
            ->withSession(['auth.mfa_passed' => true, 'auth.step_up_at' => time()])
            ->post(route('reporting.credits.settle', $bill), [
                'amount' => 501,
                'payment_method' => 'cash',
                'paid_at' => now()->toDateString(),
            ])
            ->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('credit_payments', 0);
    }
}

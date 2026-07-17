<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\SubmitCbmsInvoice;
use App\Models\AuditEvent;
use App\Models\Bill;
use App\Models\CbmsSubmission;
use App\Models\FiscalInvoiceSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CbmsDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_filter_cbms_status_without_exposing_credentials(): void
    {
        $admin = User::factory()->create(['category_id' => UserRole::Admin]);
        $this->submission('082/83-0001', 'failed', 'Network unavailable');
        $this->submission('082/83-0002', 'submitted');
        $this->enableCbms();

        $response = $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->get(route('admin.cbms.index', ['status' => 'failed']));

        $response
            ->assertOk()
            ->assertSee('IRD CBMS Status')
            ->assertSee('082/83-0001')
            ->assertDontSee('082/83-0002')
            ->assertSee('Network unavailable')
            ->assertDontSee('secret');
    }

    public function test_manual_retry_requires_step_up_and_is_audited(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['category_id' => UserRole::Admin]);
        $submission = $this->submission('082/83-0001', 'failed', 'Offline');
        $this->enableCbms();

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true, 'auth.step_up_at' => 0])
            ->post(route('admin.cbms.retry', $submission))
            ->assertRedirect(route('password.confirm'));

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true, 'auth.step_up_at' => time()])
            ->post(route('admin.cbms.retry', $submission))
            ->assertRedirect(route('admin.cbms.index'));

        $this->assertSame('pending', $submission->fresh()->status);
        Queue::assertPushed(SubmitCbmsInvoice::class, fn ($job) => $job->submissionId === $submission->id
            && filled($job->retryToken));
        $this->assertTrue(AuditEvent::where('event_type', 'cbms_submission_manual_retry')->exists());
    }

    private function submission(string $invoiceNo, string $status, ?string $error = null): CbmsSubmission
    {
        $bill = Bill::create([
            'bill_id' => (int) preg_replace('/\D/', '', $invoiceNo),
            'invoice_no' => $invoiceNo,
            'fiscal_year' => '082/83',
            'bill_amount' => 113,
            'discount' => 0,
            'taxable_amount' => 100,
            'vat_amount' => 13,
            'service_charge_amount' => 0,
            'grand_total' => 113,
            'status' => 'closed',
        ]);

        $snapshot = FiscalInvoiceSnapshot::create([
            'bill_id' => $bill->id,
            'invoice_no' => $invoiceNo,
            'fiscal_year' => '082/83',
            'invoice_at' => CarbonImmutable::parse('2025-07-17 10:00:00', config('app.timezone')),
            'seller_name' => 'Test Restaurant',
            'seller_tax_registration' => '123456789',
            'currency_symbol' => 'NPR',
            'subtotal' => 113,
            'discount' => 0,
            'service_charge' => 0,
            'taxable_sales' => 100,
            'tax_exempted_sales' => 0,
            'vat_rate' => 13,
            'vat' => 13,
            'total_sales' => 113,
            'operator_name' => 'Admin',
            'document_hash' => hash('sha256', $invoiceNo),
        ]);

        return CbmsSubmission::create([
            'fiscal_invoice_snapshot_id' => $snapshot->id,
            'payload' => ['invoice_number' => $invoiceNo],
            'status' => $status,
            'attempts' => $status === 'failed' ? 1 : 0,
            'last_error' => $error,
        ]);
    }

    private function enableCbms(): void
    {
        config()->set('services.cbms.enabled', true);
        config()->set('services.cbms.username', 'taxpayer');
        config()->set('services.cbms.password', 'secret');
    }
}

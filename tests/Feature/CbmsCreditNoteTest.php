<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\SubmitCbmsCreditNote;
use App\Http\Service\ReportingService;
use App\Models\AuditEvent;
use App\Models\Bill;
use App\Models\CbmsSubmission;
use App\Models\FiscalCreditNote;
use App\Models\FiscalInvoiceSnapshot;
use App\Models\FiscalInvoiceItem;
use App\Models\User;
use App\Services\CbmsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class CbmsCreditNoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2025-07-17 10:02:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_admin_issues_full_credit_note_and_official_payload_is_submitted_without_credentials(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['category_id' => UserRole::Admin]);
        $submission = $this->submission();
        $item = $submission->snapshot->items()->firstOrFail();
        $this->enableCbms();

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true, 'auth.step_up_at' => time()])
            ->post(route('admin.cbms.credit-note.issue', $submission), [
                'reason' => 'Customer returned the complete order',
                'refund_method' => 'cash',
                'items' => [$item->id => 1],
            ])
            ->assertRedirect(route('admin.cbms.index'));

        $creditNote = FiscalCreditNote::firstOrFail();
        $this->assertSame('CN-082/83-0001', $creditNote->credit_note_no);
        $this->assertSame('1', $creditNote->payload['ref_invoice_number']);
        $this->assertSame('1', $creditNote->payload['credit_note_number']);
        $this->assertSame('2082.083', $creditNote->payload['fiscal_year']);
        $this->assertArrayNotHasKey('username', $creditNote->payload);
        $this->assertArrayNotHasKey('password', $creditNote->payload);
        Queue::assertPushed(SubmitCbmsCreditNote::class, fn ($job) => $job->creditNoteId === $creditNote->id);
        $this->assertTrue(AuditEvent::where('event_type', 'fiscal_credit_note_issued')->exists());

        Http::fake(['https://cbms.test/api/billreturn' => Http::response('200')]);
        app(CbmsService::class)->submitCreditNote($creditNote);

        $this->assertSame('submitted', $creditNote->fresh()->status);
        Http::assertSent(fn ($request) => $request->url() === 'https://cbms.test/api/billreturn'
            && $request['username'] === 'taxpayer'
            && $request['password'] === 'secret'
            && $request['reason_for_return'] === 'Customer returned the complete order'
            && (float) $request['total_sales'] === 113.0);
    }

    public function test_return_response_101_is_rejected_and_issued_details_are_immutable(): void
    {
        $admin = User::factory()->create(['category_id' => UserRole::Admin]);
        $creditNote = app(CbmsService::class)->issueCreditNote(
            $this->submission()->snapshot,
            'Full return',
            'cash',
            $admin
        );

        try {
            $creditNote->update(['reason' => 'Changed reason']);
            $this->fail('Issued credit-note details should be immutable.');
        } catch (LogicException) {
            $this->assertSame('Full return', $creditNote->fresh()->reason);
        }

        $creditNote = $creditNote->fresh();
        $this->enableCbms();
        Http::fake(['https://cbms.test/api/billreturn' => Http::response('101')]);

        try {
            app(CbmsService::class)->submitCreditNote($creditNote);
            $this->fail('Return response 101 should not be accepted.');
        } catch (RuntimeException) {
            $this->assertSame('failed', $creditNote->fresh()->status);
            $this->assertSame('101', $creditNote->fresh()->response_code);
        }
    }

    public function test_unsubmitted_original_invoice_cannot_be_returned(): void
    {
        $admin = User::factory()->create(['category_id' => UserRole::Admin]);
        $submission = $this->submission('cash', 'failed');
        $item = $submission->snapshot->items()->firstOrFail();

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true, 'auth.step_up_at' => time()])
            ->post(route('admin.cbms.credit-note.issue', $submission), [
                'reason' => 'Full return',
                'refund_method' => 'cash',
                'items' => [$item->id => 1],
            ])
            ->assertSessionHasErrors('invoice');

        $this->assertDatabaseCount('fiscal_credit_notes', 0);
    }

    public function test_credit_note_is_printable_and_reduces_daily_net_sales_and_collections(): void
    {
        $admin = User::factory()->create(['category_id' => UserRole::Admin]);
        $creditNote = app(CbmsService::class)->issueCreditNote(
            $this->submission()->snapshot,
            'Complete order returned',
            'cash',
            $admin
        );

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->get(route('admin.cbms.credit-note.print', $creditNote))
            ->assertOk()
            ->assertSee('Credit Note / Sales Return')
            ->assertSee('CN-082/83-0001')
            ->assertSee('Test item');

        $summary = app(ReportingService::class)->dailyPaymentSummary(now()->toDateString());

        $this->assertSame(113.0, $summary['totals']['gross_collections']);
        $this->assertSame(113.0, $summary['totals']['returns']);
        $this->assertSame(0.0, $summary['totals']['collected_sales']);
        $this->assertSame(0.0, $summary['totals']['net_sales']);
        $this->assertSame(0.0, $summary['totals']['net_vat']);
    }

    public function test_partial_credit_returns_reverse_receivable_then_refund_only_the_overpayment(): void
    {
        $admin = User::factory()->create(['category_id' => UserRole::Admin]);
        $submission = $this->submission('credit', 'submitted', '082/83-0001', 2, 50);
        $item = $submission->snapshot->items()->firstOrFail();
        $service = app(CbmsService::class);

        $first = $service->issueCreditNote(
            $submission->snapshot,
            'First item returned',
            'credit',
            $admin,
            [$item->id => 1]
        );

        $this->assertSame(113.0, (float) $first->total_sales);
        $this->assertSame(113.0, (float) $first->transactions()->where('type', 'receivable_reversal')->value('amount'));
        $this->assertFalse($first->transactions()->where('type', 'payment_refund')->exists());
        $this->assertSame(63.0, $submission->snapshot->bill->fresh()->creditBalance());

        $second = $service->issueCreditNote(
            $submission->snapshot,
            'Second item returned',
            'cash',
            $admin,
            [$item->id => 1],
            'REF-1'
        );

        $this->assertSame(63.0, (float) $second->transactions()->where('type', 'receivable_reversal')->value('amount'));
        $this->assertSame(50.0, (float) $second->transactions()->where('type', 'payment_refund')->value('amount'));
        $this->assertSame('REF-1', $second->transactions()->where('type', 'payment_refund')->value('reference_no'));
        $this->assertSame(0.0, $submission->snapshot->bill->fresh()->creditBalance());
        $this->assertSame('settled', $submission->snapshot->bill->fresh()->credit_status);
        $this->assertDatabaseCount('fiscal_credit_notes', 2);
    }

    private function submission(
        string $paymentMethod = 'cash',
        string $status = 'submitted',
        string $invoiceNo = '082/83-0001',
        float $quantity = 1,
        float $paidAmount = 0
    ): CbmsSubmission
    {
        $total = 113 * $quantity;
        $taxable = 100 * $quantity;
        $vat = 13 * $quantity;
        $bill = Bill::create([
            'bill_id' => random_int(100000, 999999),
            'invoice_no' => $invoiceNo,
            'fiscal_year' => '082/83',
            'bill_amount' => $total,
            'discount' => 0,
            'taxable_amount' => $taxable,
            'vat_amount' => $vat,
            'service_charge_amount' => 0,
            'grand_total' => $total,
            'payment_method' => $paymentMethod,
            'credit_status' => $paymentMethod === 'credit' ? ($paidAmount > 0 ? 'partial' : 'open') : null,
            'credit_paid_amount' => $paidAmount,
            'status' => 'closed',
            'locked_at' => now(),
        ]);

        $snapshot = FiscalInvoiceSnapshot::create([
            'bill_id' => $bill->id,
            'invoice_no' => $bill->invoice_no,
            'fiscal_year' => $bill->fiscal_year,
            'invoice_at' => CarbonImmutable::parse('2025-07-17 10:00:00', config('app.timezone')),
            'seller_name' => 'Test Restaurant',
            'seller_tax_registration' => '123456789',
            'currency_symbol' => 'NPR',
            'subtotal' => $total,
            'discount' => 0,
            'service_charge' => 0,
            'taxable_sales' => $taxable,
            'tax_exempted_sales' => 0,
            'vat_rate' => 13,
            'vat' => $vat,
            'total_sales' => $total,
            'payment_method' => $paymentMethod,
            'operator_name' => 'Admin',
            'document_hash' => hash('sha256', (string) $bill->id),
        ]);

        FiscalInvoiceItem::create([
            'fiscal_invoice_snapshot_id' => $snapshot->id,
            'item_name' => 'Test item',
            'quantity' => $quantity,
            'unit_price' => 113,
            'line_total' => $total,
            'tax_category' => 'standard',
            'vat_rate' => 13,
        ]);

        return CbmsSubmission::create([
            'fiscal_invoice_snapshot_id' => $snapshot->id,
            'payload' => ['invoice_number' => (string) (int) substr($invoiceNo, -4)],
            'status' => $status,
            'response_code' => $status === 'submitted' ? '200' : null,
            'submitted_at' => $status === 'submitted' ? now() : null,
        ]);
    }

    private function enableCbms(): void
    {
        config()->set('services.cbms', [
            'enabled' => true,
            'url' => 'https://cbms.test',
            'username' => 'taxpayer',
            'password' => 'secret',
            'timeout' => 1,
        ]);
    }
}

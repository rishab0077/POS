<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\SubmitCbmsCreditNote;
use App\Jobs\SubmitCbmsInvoice;
use App\Models\Bill;
use App\Models\FiscalInvoiceItem;
use App\Models\FiscalInvoiceSnapshot;
use App\Models\User;
use App\Services\CbmsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CbmsAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_acceptance_mode_sends_only_the_explicitly_confirmed_invoice_and_credit_note(): void
    {
        Queue::fake();
        $this->enableAcceptanceMode();
        $service = app(CbmsService::class);
        $submission = $service->queue($this->snapshot());

        Queue::assertNotPushed(SubmitCbmsInvoice::class);
        Http::fake(['https://cbapi.ird.gov.np/api/*' => Http::response('200')]);
        $this->artisan('cbms:accept', ['type' => 'invoice', 'id' => $submission->id])
            ->assertExitCode(1);
        Http::assertNothingSent();
        (new SubmitCbmsInvoice($submission->id))->handle($service);
        Http::assertNothingSent();

        $this->artisan('cbms:accept', [
            'type' => 'invoice',
            'id' => $submission->id,
            '--confirm' => 'SEND TO IRD',
        ])->expectsOutputToContain('response 200')->assertExitCode(0);

        $this->assertSame('submitted', $submission->fresh()->status);
        $admin = User::factory()->create(['category_id' => UserRole::Admin]);
        $creditNote = $service->issueCreditNote($submission->snapshot->fresh('cbmsSubmission'), 'Acceptance return', 'cash', $admin);
        Queue::assertNotPushed(SubmitCbmsCreditNote::class);
        (new SubmitCbmsCreditNote($creditNote->id))->handle($service);
        $this->assertSame('pending', $creditNote->fresh()->status);

        $this->artisan('cbms:accept', [
            'type' => 'credit-note',
            'id' => $creditNote->id,
            '--confirm' => 'SEND TO IRD',
        ])->expectsOutputToContain('response 200')->assertExitCode(0);

        $this->assertSame('submitted', $creditNote->fresh()->status);
        Http::assertSentCount(2);
    }

    public function test_acceptance_refuses_to_send_when_more_than_one_record_is_unresolved(): void
    {
        $this->enableAcceptanceMode();
        $first = app(CbmsService::class)->queue($this->snapshot('082/83-0001'));
        app(CbmsService::class)->queue($this->snapshot('082/83-0002'));
        Http::fake();

        $this->artisan('cbms:accept', [
            'type' => 'invoice',
            'id' => $first->id,
            '--confirm' => 'SEND TO IRD',
        ])->expectsOutputToContain('exactly one unresolved')->assertExitCode(1);

        Http::assertNothingSent();
    }

    private function snapshot(string $invoiceNo = '082/83-0001'): FiscalInvoiceSnapshot
    {
        $bill = Bill::create([
            'bill_id' => random_int(100000, 999999),
            'invoice_no' => $invoiceNo,
            'fiscal_year' => '082/83',
            'bill_amount' => 113,
            'discount' => 0,
            'taxable_amount' => 100,
            'vat_amount' => 13,
            'service_charge_amount' => 0,
            'grand_total' => 113,
            'payment_method' => 'cash',
            'status' => 'closed',
            'locked_at' => now(),
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
            'payment_method' => 'cash',
            'operator_name' => 'Admin',
            'document_hash' => hash('sha256', $invoiceNo),
        ]);

        FiscalInvoiceItem::create([
            'fiscal_invoice_snapshot_id' => $snapshot->id,
            'item_name' => 'Acceptance item',
            'quantity' => 1,
            'unit_price' => 113,
            'line_total' => 113,
            'tax_category' => 'standard',
            'vat_rate' => 13,
        ]);

        return $snapshot;
    }

    private function enableAcceptanceMode(): void
    {
        config()->set('services.cbms', [
            'enabled' => true,
            'acceptance_mode' => true,
            'url' => 'https://cbapi.ird.gov.np',
            'username' => 'taxpayer',
            'password' => 'secret',
            'timeout' => 1,
        ]);
    }
}

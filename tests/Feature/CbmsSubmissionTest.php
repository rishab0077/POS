<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\FiscalInvoiceSnapshot;
use App\Services\CbmsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CbmsSubmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_official_bill_payload_is_stored_without_credentials_and_submitted(): void
    {
        $snapshot = $this->snapshot();
        $service = app(CbmsService::class);
        $submission = $service->queue($snapshot);

        $this->assertSame('2082.083', $submission->payload['fiscal_year']);
        $this->assertSame('1', $submission->payload['invoice_number']);
        $this->assertSame('2082.04.01', $submission->payload['invoice_date']);
        $this->assertArrayNotHasKey('username', $submission->payload);
        $this->assertArrayNotHasKey('password', $submission->payload);

        $this->enableCbms();
        Http::fake(['https://cbms.test/api/bill' => Http::response('200')]);

        $service->submit($submission);

        $this->assertSame('submitted', $submission->fresh()->status);
        $this->assertSame('200', $submission->fresh()->response_code);
        Http::assertSent(fn ($request) => $request['username'] === 'taxpayer'
            && $request['password'] === 'secret'
            && $request['seller_pan'] === '123456789'
            && (float) $request['taxable_sales_vat'] === 100.0
            && (float) $request['vat'] === 13.0
            && (float) $request['total_sales'] === 113.0);
    }

    public function test_offline_failure_stays_queued_and_duplicate_response_completes_retry(): void
    {
        $submission = app(CbmsService::class)->queue($this->snapshot());
        $this->enableCbms();
        $attempt = 0;
        Http::fake(function () use (&$attempt) {
            return ++$attempt === 1
                ? throw new ConnectionException('offline')
                : Http::response('101');
        });

        try {
            app(CbmsService::class)->submit($submission);
            $this->fail('Offline CBMS submission should throw.');
        } catch (ConnectionException) {
            $this->assertSame('failed', $submission->fresh()->status);
            $this->assertSame(1, $submission->fresh()->attempts);
        }

        app(CbmsService::class)->submit($submission->fresh());

        $this->assertSame('submitted', $submission->fresh()->status);
        $this->assertSame('101', $submission->fresh()->response_code);
        $this->assertSame(2, $submission->fresh()->attempts);
    }

    private function snapshot(): FiscalInvoiceSnapshot
    {
        $bill = Bill::create([
            'bill_id' => random_int(100000, 999999),
            'invoice_no' => '082/83-0001',
            'fiscal_year' => '082/83',
            'bill_amount' => 113,
            'discount' => 0,
            'taxable_amount' => 100,
            'vat_amount' => 13,
            'service_charge_amount' => 0,
            'grand_total' => 113,
            'status' => 'closed',
        ]);

        return FiscalInvoiceSnapshot::create([
            'bill_id' => $bill->id,
            'invoice_no' => $bill->invoice_no,
            'fiscal_year' => $bill->fiscal_year,
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
            'document_hash' => hash('sha256', (string) $bill->id),
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

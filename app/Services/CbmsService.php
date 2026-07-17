<?php

namespace App\Services;

use App\Jobs\SubmitCbmsInvoice;
use App\Jobs\SubmitCbmsCreditNote;
use App\Models\CbmsSubmission;
use App\Models\FiscalCreditNote;
use App\Models\FiscalInvoiceSnapshot;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class CbmsService
{
    public function queue(FiscalInvoiceSnapshot $snapshot): CbmsSubmission
    {
        $submission = CbmsSubmission::firstOrCreate(
            ['fiscal_invoice_snapshot_id' => $snapshot->id],
            ['payload' => $this->payload($snapshot)]
        );

        if ($this->ready()) {
            SubmitCbmsInvoice::dispatch($submission->id)->afterCommit();
        }

        return $submission;
    }

    public function ready(): bool
    {
        return (bool) config('services.cbms.enabled')
            && filled(config('services.cbms.username'))
            && filled(config('services.cbms.password'));
    }

    public function issueCreditNote(
        FiscalInvoiceSnapshot $snapshot,
        string $reason,
        string $refundMethod,
        User $operator,
        ?array $quantities = null,
        ?string $referenceNo = null
    ): FiscalCreditNote {
        if ($snapshot->cbmsSubmission?->status !== 'submitted') {
            throw ValidationException::withMessages([
                'invoice' => 'The original invoice must be accepted by CBMS before issuing its credit note.',
            ]);
        }

        $creditNote = DB::transaction(function () use (
            $snapshot,
            $reason,
            $refundMethod,
            $operator,
            $quantities,
            $referenceNo
        ) {
            $snapshot = FiscalInvoiceSnapshot::with(['items', 'creditNotes.items', 'bill'])
                ->findOrFail($snapshot->id);
            $bill = $snapshot->bill()->lockForUpdate()->firstOrFail();
            $returnedItems = $snapshot->creditNotes->flatMap->items;
            $items = [];

            foreach ($snapshot->items as $item) {
                $previousQuantity = (float) $returnedItems
                    ->where('fiscal_invoice_item_id', $item->id)
                    ->sum('quantity');
                $remaining = round((float) $item->quantity - $previousQuantity, 3);
                $quantity = $quantities === null
                    ? $remaining
                    : round((float) ($quantities[$item->id] ?? 0), 3);

                if ($quantity < 0 || $quantity > $remaining + 0.0005) {
                    throw ValidationException::withMessages([
                        "items.{$item->id}" => "Return quantity for {$item->item_name} exceeds {$remaining}.",
                    ]);
                }

                if ($quantity <= 0) {
                    continue;
                }

                $previousLineTotal = (float) $returnedItems
                    ->where('fiscal_invoice_item_id', $item->id)
                    ->sum('line_total');
                $lineTotal = abs($quantity - $remaining) < 0.0005
                    ? round((float) $item->line_total - $previousLineTotal, 2)
                    : round((float) $item->unit_price * $quantity, 2);
                $inventoryRestoreQuantities = $this->inventoryRestoreQuantities(
                    $item,
                    $returnedItems->where('fiscal_invoice_item_id', $item->id),
                    $quantity,
                    $remaining
                );

                $items[] = [
                    'fiscal_invoice_item_id' => $item->id,
                    'item_name' => $item->item_name,
                    'category_name' => $item->category_name,
                    'inventory_restore_quantities' => $inventoryRestoreQuantities ?: null,
                    'quantity' => $quantity,
                    'unit_price' => $item->unit_price,
                    'line_total' => $lineTotal,
                    'tax_category' => $item->tax_category,
                    'vat_rate' => $item->vat_rate,
                ];
            }

            if ($items === []) {
                throw ValidationException::withMessages(['items' => 'Select at least one return quantity.']);
            }

            $totals = $this->creditTotals($snapshot, $returnedItems, collect($items));
            $previousCredit = (float) $snapshot->creditNotes->sum('total_sales');
            $outstanding = max(round((float) $snapshot->total_sales - $previousCredit - (float) $bill->credit_paid_amount, 2), 0);
            $receivableReversal = $snapshot->payment_method === 'credit'
                ? min($totals['total_sales'], $outstanding)
                : 0;
            $paymentRefund = round($totals['total_sales'] - $receivableReversal, 2);

            if ($paymentRefund > 0 && $refundMethod === 'credit') {
                throw ValidationException::withMessages([
                    'refund_method' => 'Select a cash, card, or wallet method for the paid amount being refunded.',
                ]);
            }

            if ($snapshot->payment_method !== 'credit' && $refundMethod === 'credit') {
                throw ValidationException::withMessages([
                    'refund_method' => 'A paid invoice requires a cash, card, or wallet refund method.',
                ]);
            }

            $issuedAt = now();
            $number = app(NepalFiscalYearService::class)->nextCreditNoteNumber($issuedAt);
            $payload = $this->creditNotePayload($snapshot, $number, $issuedAt, $reason, $totals);
            $recordedMethod = $paymentRefund > 0 ? $refundMethod : 'credit';
            $legalRecord = array_merge($payload, [
                'refund_method' => $recordedMethod,
                'operator_id' => $operator->id,
                'operator_name' => $operator->name,
                'issued_at' => $issuedAt->format('Y-m-d H:i:s'),
                'items' => $items,
            ]);

            $creditNote = FiscalCreditNote::create([
                'fiscal_invoice_snapshot_id' => $snapshot->id,
                'credit_note_no' => $number['credit_note_no'],
                'fiscal_year' => $number['fiscal_year'],
                'issued_at' => $issuedAt,
                'reason' => $reason,
                'refund_method' => $recordedMethod,
                'total_sales' => $totals['total_sales'],
                'taxable_sales' => $totals['taxable_sales'],
                'tax_exempted_sales' => $totals['tax_exempted_sales'],
                'vat' => $totals['vat'],
                'operator_id' => $operator->id,
                'operator_name' => $operator->name,
                'document_hash' => hash('sha256', json_encode($legalRecord, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                'payload' => $payload,
            ]);

            $creditNote->items()->createMany($items);

            if ($receivableReversal > 0) {
                $creditNote->transactions()->create([
                    'type' => 'receivable_reversal',
                    'amount' => $receivableReversal,
                    'recorded_at' => $issuedAt,
                    'recorded_by' => $operator->id,
                ]);
            }

            if ($paymentRefund > 0) {
                $creditNote->transactions()->create([
                    'type' => 'payment_refund',
                    'amount' => $paymentRefund,
                    'payment_method' => $refundMethod,
                    'reference_no' => $referenceNo,
                    'recorded_at' => $issuedAt,
                    'recorded_by' => $operator->id,
                ]);
            }

            if ($snapshot->payment_method === 'credit') {
                $returnedAmount = round($previousCredit + $totals['total_sales'], 2);
                $netDue = max(round((float) $snapshot->total_sales - $returnedAmount, 2), 0);
                $settled = (float) $bill->credit_paid_amount >= $netDue;

                $bill->update([
                    'credit_returned_amount' => $returnedAmount,
                    'credit_status' => $settled ? 'settled' : ((float) $bill->credit_paid_amount > 0 ? 'partial' : 'open'),
                    'credit_settled_at' => $settled ? $issuedAt : null,
                ]);
            }

            return $creditNote;
        });

        if ($this->ready()) {
            SubmitCbmsCreditNote::dispatch($creditNote->id)->afterCommit();
        }

        return $creditNote;
    }

    private function inventoryRestoreQuantities($invoiceItem, $previousReturns, float $quantity, float $remaining): array
    {
        $consumption = collect($invoiceItem->inventory_consumption ?? []);
        $previousAllocations = $previousReturns
            ->flatMap(fn ($item) => $item->inventory_restore_quantities ?? []);

        return $consumption->map(function ($stock) use (
            $invoiceItem,
            $previousAllocations,
            $quantity,
            $remaining
        ) {
            $previous = (float) $previousAllocations
                ->where('stock_item_id', $stock['stock_item_id'])
                ->sum('quantity');
            $allocated = abs($quantity - $remaining) < 0.0005
                ? round((float) $stock['quantity'] - $previous, 3)
                : round((float) $stock['quantity'] * $quantity / (float) $invoiceItem->quantity, 3);

            return array_merge($stock, ['quantity' => $allocated]);
        })->filter(fn ($stock) => $stock['quantity'] > 0)->values()->all();
    }

    public function submit(CbmsSubmission $submission): void
    {
        if (!$this->ready()) {
            throw new RuntimeException('CBMS is not enabled or credentials are missing.');
        }

        $payload = $submission->payload;
        $this->validatePan($payload['seller_pan'], 'Seller PAN');

        if (filled($payload['buyer_pan'])) {
            $this->validatePan($payload['buyer_pan'], 'Buyer PAN');
        }

        $submission->update([
            'status' => 'submitting',
            'attempts' => $submission->attempts + 1,
            'last_attempt_at' => now(),
            'last_error' => null,
        ]);

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout((int) config('services.cbms.timeout', 15))
                ->post(rtrim(config('services.cbms.url'), '/') . '/api/bill', array_merge($payload, [
                    'username' => config('services.cbms.username'),
                    'password' => config('services.cbms.password'),
                    // ponytail: IRD defines no cutoff; make this configurable if IRD prescribes one.
                    'isrealtime' => $submission->snapshot->invoice_at->diffInMinutes(now()) < 5,
                    'datetimeClient' => now()->format('Y-m-d H:i:s'),
                ]));

            $this->recordResponse($submission, $response);
        } catch (Throwable $exception) {
            $submission->update([
                'status' => 'failed',
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);

            throw $exception;
        }
    }

    public function submitCreditNote(FiscalCreditNote $creditNote): void
    {
        if (!$this->ready()) {
            throw new RuntimeException('CBMS is not enabled or credentials are missing.');
        }

        $payload = $creditNote->payload;
        $this->validatePan($payload['seller_pan'], 'Seller PAN');

        if (filled($payload['buyer_pan'])) {
            $this->validatePan($payload['buyer_pan'], 'Buyer PAN');
        }

        $creditNote->update([
            'status' => 'submitting',
            'attempts' => $creditNote->attempts + 1,
            'last_attempt_at' => now(),
            'last_error' => null,
        ]);

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout((int) config('services.cbms.timeout', 15))
                ->post(rtrim(config('services.cbms.url'), '/') . '/api/billreturn', array_merge($payload, [
                    'username' => config('services.cbms.username'),
                    'password' => config('services.cbms.password'),
                    // ponytail: IRD defines no cutoff; make this configurable if IRD prescribes one.
                    'isrealtime' => $creditNote->issued_at->diffInMinutes(now()) < 5,
                    'datetimeClient' => now()->format('Y-m-d H:i:s'),
                ]));

            $this->recordCreditNoteResponse($creditNote, $response);
        } catch (Throwable $exception) {
            $creditNote->update([
                'status' => 'failed',
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);

            throw $exception;
        }
    }

    private function payload(FiscalInvoiceSnapshot $snapshot): array
    {
        $bsDate = app(NepaliDateService::class)->format($snapshot->invoice_at);

        return [
            'seller_pan' => $snapshot->seller_tax_registration,
            'buyer_pan' => $snapshot->buyer_pan ?: '',
            'buyer_name' => $snapshot->buyer_name ?: '',
            'fiscal_year' => $this->fiscalYear($snapshot->fiscal_year),
            'invoice_number' => $this->invoiceNumber($snapshot->invoice_no),
            'invoice_date' => str_replace('-', '.', $bsDate),
            'total_sales' => (float) $snapshot->total_sales,
            'taxable_sales_vat' => (float) $snapshot->taxable_sales,
            'vat' => (float) $snapshot->vat,
            'excisable_amount' => 0,
            'excise' => 0,
            'taxable_sales_hst' => 0,
            'hst' => 0,
            'amount_for_esf' => 0,
            'esf' => 0,
            'export_sales' => 0,
            'tax_exempted_sales' => (float) $snapshot->tax_exempted_sales,
        ];
    }

    private function creditNotePayload(
        FiscalInvoiceSnapshot $snapshot,
        array $number,
        $issuedAt,
        string $reason,
        array $totals
    ): array {
        return [
            'seller_pan' => $snapshot->seller_tax_registration,
            'buyer_pan' => $snapshot->buyer_pan ?: '',
            'fiscal_year' => $this->fiscalYear($number['fiscal_year']),
            'buyer_name' => $snapshot->buyer_name ?: '',
            'ref_invoice_number' => $this->invoiceNumber($snapshot->invoice_no),
            'credit_note_number' => $this->invoiceNumber($number['credit_note_no']),
            'credit_note_date' => str_replace('-', '.', app(NepaliDateService::class)->format($issuedAt)),
            'reason_for_return' => $reason,
            'total_sales' => $totals['total_sales'],
            'taxable_sales_vat' => $totals['taxable_sales'],
            'vat' => $totals['vat'],
            'excisable_amount' => 0,
            'excise' => 0,
            'taxable_sales_hst' => 0,
            'hst' => 0,
            'amount_for_esf' => 0,
            'esf' => 0,
            'export_sales' => 0,
            'tax_exempted_sales' => $totals['tax_exempted_sales'],
        ];
    }

    private function creditTotals(FiscalInvoiceSnapshot $snapshot, $previousItems, $newItems): array
    {
        $originalStandard = (float) $snapshot->items->where('tax_category', 'standard')->sum('line_total');
        $originalExempt = (float) $snapshot->items->where('tax_category', 'exempt')->sum('line_total');
        $returnedStandard = (float) $previousItems->where('tax_category', 'standard')->sum('line_total')
            + (float) $newItems->where('tax_category', 'standard')->sum('line_total');
        $returnedExempt = (float) $previousItems->where('tax_category', 'exempt')->sum('line_total')
            + (float) $newItems->where('tax_category', 'exempt')->sum('line_total');
        $previousNotes = $snapshot->creditNotes;

        $targetTaxable = $originalStandard > 0
            ? round((float) $snapshot->taxable_sales * min($returnedStandard / $originalStandard, 1), 2)
            : 0;
        $targetExempt = $originalExempt > 0
            ? round((float) $snapshot->tax_exempted_sales * min($returnedExempt / $originalExempt, 1), 2)
            : 0;
        $targetVat = $originalStandard > 0
            ? round((float) $snapshot->vat * min($returnedStandard / $originalStandard, 1), 2)
            : 0;
        $taxable = round($targetTaxable - (float) $previousNotes->sum('taxable_sales'), 2);
        $exempt = round($targetExempt - (float) $previousNotes->sum('tax_exempted_sales'), 2);
        $vat = round($targetVat - (float) $previousNotes->sum('vat'), 2);

        return [
            'taxable_sales' => $taxable,
            'tax_exempted_sales' => $exempt,
            'vat' => $vat,
            'total_sales' => round($taxable + $exempt + $vat, 2),
        ];
    }

    private function fiscalYear(string $fiscalYear): string
    {
        if (!preg_match('/^(\d{3})\/(\d{2})$/', $fiscalYear, $parts)) {
            throw new RuntimeException("Unsupported fiscal year format: {$fiscalYear}");
        }

        return (2000 + (int) $parts[1]) . '.' . str_pad($parts[2], 3, '0', STR_PAD_LEFT);
    }

    private function invoiceNumber(string $invoiceNumber): string
    {
        if (!preg_match('/-(\d+)$/', $invoiceNumber, $parts)) {
            throw new RuntimeException("Unsupported invoice number format: {$invoiceNumber}");
        }

        return (string) (int) $parts[1];
    }

    private function validatePan(string $pan, string $label): void
    {
        if (!preg_match('/^\d{9}$/', $pan)) {
            throw new RuntimeException("{$label} must contain exactly 9 digits.");
        }
    }

    private function recordResponse(CbmsSubmission $submission, Response $response): void
    {
        $body = trim($response->body());
        $code = trim($body, "\" \t\n\r\0\x0B");

        if ($response->successful() && in_array($code, ['200', '101'], true)) {
            $submission->update([
                'status' => 'submitted',
                'response_code' => $code,
                'last_response' => mb_substr($body, 0, 2000),
                'last_error' => null,
                'submitted_at' => now(),
            ]);

            return;
        }

        $submission->update([
            'status' => 'failed',
            'response_code' => $code ?: (string) $response->status(),
            'last_response' => mb_substr($body, 0, 2000),
            'last_error' => "CBMS rejected the invoice with code " . ($code ?: $response->status()) . '.',
        ]);

        throw new RuntimeException($submission->last_error);
    }

    private function recordCreditNoteResponse(FiscalCreditNote $creditNote, Response $response): void
    {
        $body = trim($response->body());
        $code = trim($body, "\" \t\n\r\0\x0B");

        if ($response->successful() && $code === '200') {
            $creditNote->update([
                'status' => 'submitted',
                'response_code' => $code,
                'last_response' => mb_substr($body, 0, 2000),
                'last_error' => null,
                'submitted_at' => now(),
            ]);

            return;
        }

        $creditNote->update([
            'status' => 'failed',
            'response_code' => $code ?: (string) $response->status(),
            'last_response' => mb_substr($body, 0, 2000),
            'last_error' => 'CBMS rejected the credit note with code ' . ($code ?: $response->status()) . '.',
        ]);

        throw new RuntimeException($creditNote->last_error);
    }
}

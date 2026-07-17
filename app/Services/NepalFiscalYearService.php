<?php

namespace App\Services;

use App\Models\InvoiceSequence;
use Carbon\CarbonInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class NepalFiscalYearService
{
    public function __construct(private NepaliDateService $nepaliDateService)
    {
    }

    public function fiscalYear(?CarbonInterface $date = null): string
    {
        $date = $date ? CarbonImmutable::instance($date) : now()->toImmutable();
        $bsDate = $this->nepaliDateService->toBs($date);
        $bsYear = $bsDate['year'];
        $startsAt = $this->nepaliDateService->shrawanOneAdDate($bsYear);

        if ($date->startOfDay()->lessThan($startsAt)) {
            return $this->formatFiscalYear($bsYear - 1, $bsYear);
        }

        return $this->formatFiscalYear($bsYear, $bsYear + 1);
    }

    public function nextInvoiceNumber(?CarbonInterface $date = null): array
    {
        $fiscalYear = $this->fiscalYear($date);

        return [
            'fiscal_year' => $fiscalYear,
            'invoice_no' => sprintf('%s-%04d', $fiscalYear, $this->nextNumber($fiscalYear)),
        ];
    }

    public function nextCreditNoteNumber(?CarbonInterface $date = null): array
    {
        $fiscalYear = $this->fiscalYear($date);

        return [
            'fiscal_year' => $fiscalYear,
            'credit_note_no' => sprintf('CN-%s-%04d', $fiscalYear, $this->nextNumber("credit-note:{$fiscalYear}")),
        ];
    }

    private function nextNumber(string $sequenceKey): int
    {
        return DB::transaction(function () use ($sequenceKey) {
            $sequence = InvoiceSequence::where('fiscal_year', $sequenceKey)->lockForUpdate()->first();

            if (!$sequence) {
                $sequence = InvoiceSequence::create([
                    'fiscal_year' => $sequenceKey,
                    'next_number' => 1,
                ]);
            }

            $number = $sequence->next_number;
            $sequence->next_number = $number + 1;
            $sequence->save();

            return $number;
        });
    }

    private function formatFiscalYear(int $startBsYear, int $endBsYear): string
    {
        return sprintf('%03d/%02d', $startBsYear % 1000, $endBsYear % 100);
    }
}

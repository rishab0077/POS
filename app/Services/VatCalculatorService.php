<?php

namespace App\Services;

class VatCalculatorService
{
    public function __construct(private BusinessConfigurationService $businessConfiguration)
    {
    }

    public function calculate(float $subtotal, float $discount = 0): array
    {
        $vatRate = $this->vatRate();
        $vatBasisPoints = (int) round($vatRate * 100);
        $subtotalCents = max($this->toCents($subtotal), 0);
        $discountCents = max(min($this->toCents($discount), $subtotalCents), 0);
        $afterDiscountCents = $subtotalCents - $discountCents;
        $serviceChargeCents = $this->serviceChargeCents($afterDiscountCents);
        $grossCents = $afterDiscountCents + $serviceChargeCents;

        if ($this->isVatInclusive()) {
            $taxableCents = (int) round($grossCents * 10000 / (10000 + $vatBasisPoints));
            $vatCents = $grossCents - $taxableCents;
            $grandTotalCents = $grossCents;
        } else {
            $taxableCents = $grossCents;
            $vatCents = (int) round($grossCents * $vatBasisPoints / 10000);
            $grandTotalCents = $grossCents + $vatCents;
        }

        return [
            'subtotal' => $this->fromCents($subtotalCents),
            'discount' => $this->fromCents($discountCents),
            'service_charge_amount' => $this->fromCents($serviceChargeCents),
            'taxable_amount' => $this->fromCents($taxableCents),
            'vat_amount' => $this->fromCents($vatCents),
            'grand_total' => $this->fromCents($grandTotalCents),
            'vat_rate' => $vatRate,
        ];
    }

    public function vatRate(): float
    {
        return $this->businessConfiguration->vatRate();
    }

    public function isVatInclusive(): bool
    {
        return (bool) config('pos.tax.vat_inclusive', true);
    }

    public function serviceChargeEnabled(): bool
    {
        return (bool) config('pos.tax.service_charge_enabled', false);
    }

    public function serviceChargeRate(): float
    {
        return (float) config('pos.tax.service_charge_rate', 0);
    }

    private function serviceChargeCents(int $amountCents): int
    {
        if (!$this->serviceChargeEnabled()) {
            return 0;
        }

        return (int) round($amountCents * $this->serviceChargeRate() / 100);
    }

    private function toCents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function fromCents(int $amount): float
    {
        return $amount / 100;
    }
}

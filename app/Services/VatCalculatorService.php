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
        $subtotalAfterDiscount = max($subtotal - $discount, 0);
        $serviceChargeAmount = $this->serviceChargeAmount($subtotalAfterDiscount);
        $gross = $subtotalAfterDiscount + $serviceChargeAmount;

        if ($this->isVatInclusive()) {
            $taxableAmount = $gross / (1 + ($vatRate / 100));
            $vatAmount = $gross - $taxableAmount;
            $grandTotal = $gross;
        } else {
            $taxableAmount = $gross;
            $vatAmount = $gross * ($vatRate / 100);
            $grandTotal = $gross + $vatAmount;
        }

        return [
            'subtotal' => round($subtotal, 2),
            'discount' => round($discount, 2),
            'service_charge_amount' => round($serviceChargeAmount, 2),
            'taxable_amount' => round($taxableAmount, 2),
            'vat_amount' => round($vatAmount, 2),
            'grand_total' => round($grandTotal, 2),
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

    private function serviceChargeAmount(float $amount): float
    {
        if (!$this->serviceChargeEnabled()) {
            return 0;
        }

        return $amount * ($this->serviceChargeRate() / 100);
    }
}

<?php

namespace App\Services;

use App\Http\Service\RestaurantService;
use App\Models\Bill;

class BusinessConfigurationService
{
    public function __construct(private RestaurantService $restaurantService)
    {
    }

    /**
     * Database restaurant settings are authoritative at runtime. Environment
     * config remains the bootstrap fallback for a new installation.
     */
    public function details(): array
    {
        $restaurant = $this->restaurantService->getRestaurantDetails();
        $configuredBusiness = config('pos.business', []);

        return [
            'name' => $restaurant?->name ?: ($configuredBusiness['name'] ?? config('app.name', 'Restaurant POS')),
            'address' => $restaurant?->address ?: ($configuredBusiness['address'] ?? ''),
            'phone' => $restaurant?->phone ?: '',
            'email' => $restaurant?->email ?: '',
            'website' => $restaurant?->website ?: '',
            'tagline' => $restaurant?->tagline ?: '',
            'tax_registration' => $restaurant?->GST
                ?: ($configuredBusiness['vat'] ?? $configuredBusiness['pan'] ?? ''),
            'vat_rate' => (float) ($restaurant?->tax_rate ?? config('pos.tax.vat_rate', 13)),
            'currency_symbol' => $restaurant?->currency_symbol ?: 'NPR',
        ];
    }

    public function vatRate(): float
    {
        return $this->details()['vat_rate'];
    }

    /**
     * Reprints show the effective rate represented by the stored bill totals,
     * even after the restaurant's current VAT rate changes.
     */
    public function vatRateForBill(Bill $bill): float
    {
        $taxableAmount = (float) $bill->taxable_amount;

        if ($taxableAmount > 0) {
            return round(((float) $bill->vat_amount / $taxableAmount) * 100, 2);
        }

        return $this->vatRate();
    }
}

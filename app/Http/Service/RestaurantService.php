<?php

namespace App\Http\Service;

use App\Models\PrintStation;
use App\Models\Restaurant;
use Illuminate\Support\Facades\Schema;

class RestaurantService extends Service
{
    public function getRestaurantDetails()
    {
        return Restaurant::getCachedRestaurants()->first();
    }

    public function isRestaurantConfigured()
    {
        $restaurant = $this->getRestaurantDetails();
        return !is_null($restaurant) && !is_null($restaurant->name) && !is_null($restaurant->address);
    }

    public function isKitchenModuleEnabled()
    {
        return (bool) ($this->getRestaurantDetails()?->kitchen_module_enabled ?? false);
    }

    public function isWaiterModuleEnabled()
    {
        return (bool) ($this->getRestaurantDetails()?->waiter_module_enabled ?? false);
    }

    public function isPrintBillEnabled()
    {
        return $this->hasEnabledPrintStationFor('counter');
    }

    public function isKOTPrintEnabled()
    {
        return $this->hasEnabledPrintStationFor('kitchen')
            || $this->hasEnabledPrintStationFor('bar');
    }

    public function getKitchenPrinter()
    {
        return $this->getRestaurantDetails()?->kitchen_printer ?? config('predefined_options.printer.kitchen');
    }

    public function getBarPrinter()
    {
        return $this->getRestaurantDetails()?->bar_printer
            ?? $this->getRestaurantDetails()?->kitchen_printer
            ?? config('predefined_options.printer.bar');
    }

    public function getBillerPrinter()
    {
        return $this->getRestaurantDetails()?->biller_printer ?? config('predefined_options.printer.biller');
    }

    public function getCounterPrinter()
    {
        return $this->getRestaurantDetails()?->counter_printer
            ?? $this->getRestaurantDetails()?->biller_printer
            ?? config('predefined_options.printer.counter');
    }

    public function hasEnabledPrintStationFor(string $printerKey): bool
    {
        try {
            if (!Schema::hasTable('print_stations')) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        return PrintStation::enabled()
            ->get()
            ->contains(fn (PrintStation $station) => $station->hasPrinterKey($printerKey));
    }

    public function hasOnlinePrintStationFor(string $printerKey): bool
    {
        if (!$this->hasEnabledPrintStationFor($printerKey)) {
            return false;
        }

        return PrintStation::enabled()
            ->get()
            ->contains(fn (PrintStation $station) => $station->isOnline() && $station->hasPrinterKey($printerKey));
    }
}

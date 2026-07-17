<?php

namespace Tests\Feature;

use App\Http\Service\RestaurantService;
use App\Models\PrintStation;
use App\Models\Restaurant;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrintRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_printer_names_do_not_enable_unclaimable_print_jobs(): void
    {
        $this->seed(DatabaseSeeder::class);

        Restaurant::firstOrFail()->update([
            'counter_printer' => 'LEGACY_COUNTER',
            'biller_printer' => 'LEGACY_BILLER',
            'kitchen_printer' => 'LEGACY_KITCHEN',
            'bar_printer' => 'LEGACY_BAR',
        ]);

        $service = app(RestaurantService::class);

        $this->assertFalse($service->isPrintBillEnabled());
        $this->assertFalse($service->isKOTPrintEnabled());

        PrintStation::create([
            'name' => 'Kitchen station',
            'token_hash' => PrintStation::hashToken('kitchen-token'),
            'printer_map' => ['kitchen' => 'KITCHEN_KOT'],
            'enabled' => true,
        ]);

        $this->assertTrue($service->hasEnabledPrintStationFor('kitchen'));
        $this->assertFalse($service->hasEnabledPrintStationFor('bar'));
        $this->assertFalse($service->hasEnabledPrintStationFor('counter'));
        $this->assertTrue($service->isKOTPrintEnabled());
        $this->assertFalse($service->isPrintBillEnabled());
    }
}

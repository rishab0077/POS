<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\BusinessConfigurationService;
use App\Services\PrintPayloadService;
use App\Services\VatCalculatorService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_restaurant_settings_drive_vat_and_printed_identity(): void
    {
        config()->set('pos.business.name', 'Environment Cafe');
        config()->set('pos.business.address', 'Environment Address');
        config()->set('pos.business.pan', 'ENV-PAN');
        config()->set('pos.business.vat', 'ENV-VAT');
        config()->set('pos.tax.vat_rate', 13);

        $this->seed(DatabaseSeeder::class);

        $restaurant = Restaurant::firstOrFail();
        $restaurant->update([
            'name' => 'Database Cafe',
            'address' => 'Database Address',
            'GST' => 'DB-PAN-VAT',
            'tax_rate' => 15,
            'currency_symbol' => 'NPR',
        ]);

        $business = app(BusinessConfigurationService::class)->details();
        $this->assertSame('Database Cafe', $business['name']);
        $this->assertSame('Database Address', $business['address']);
        $this->assertSame('DB-PAN-VAT', $business['tax_registration']);
        $this->assertSame(15.0, app(VatCalculatorService::class)->vatRate());

        $bill = Bill::create([
            'bill_id' => 1,
            'invoice_no' => 'TEST-001',
            'bill_amount' => 100,
            'discount' => 0,
            'taxable_amount' => 100,
            'vat_amount' => 15,
            'service_charge_amount' => 0,
            'grand_total' => 115,
            'status' => 'closed',
            'payment_method' => 'cash',
            'locked_at' => now(),
        ]);

        $printBuffer = base64_decode(
            app(PrintPayloadService::class)->billPayload($bill->id),
            true
        );

        $this->assertIsString($printBuffer);
        $this->assertStringContainsString('Database Cafe', $printBuffer);
        $this->assertStringContainsString('Database Address', $printBuffer);
        $this->assertStringContainsString('DB-PAN-VAT', $printBuffer);
        $this->assertStringContainsString('VAT 15.00%', $printBuffer);
    }

    public function test_restaurant_seeding_does_not_overwrite_runtime_database_settings(): void
    {
        $this->seed(DatabaseSeeder::class);

        Restaurant::firstOrFail()->update([
            'name' => 'Operator Managed Name',
            'tax_rate' => 12.5,
        ]);

        config()->set('pos.business.name', 'Changed Environment Name');
        config()->set('pos.tax.vat_rate', 18);
        $this->seed(\Database\Seeders\RestaurantSeeder::class);

        $restaurant = Restaurant::firstOrFail();
        $this->assertSame('Operator Managed Name', $restaurant->name);
        $this->assertSame(12.5, (float) $restaurant->tax_rate);
    }

    public function test_sidebar_uses_restaurant_name_as_its_brand(): void
    {
        $this->seed(DatabaseSeeder::class);
        Restaurant::firstOrFail()->update(['name' => 'Amber Cafe']);

        $this->actingAs(User::firstOrFail());

        $this->assertStringContainsString(
            '<span class="text-lg font-semibold tracking-widest text-white uppercase">Amber Cafe</span>',
            view('admin.index')->render()
        );
    }
}

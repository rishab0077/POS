<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\PrintAttempt;
use App\Models\PrintJob;
use App\Models\PrintStation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrintStationDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_exposes_queue_counts_and_legacy_jobs_safely(): void
    {
        $admin = User::factory()->create(['category_id' => UserRole::Admin]);
        $station = $this->station(['counter' => 'COUNTER']);

        $this->job('pending-counter', [
            'status' => PrintJob::STATUS_PENDING,
            'printer_key' => 'counter',
            'copy_type' => null,
            'type' => 'bill',
        ]);
        $this->job('processing-kitchen', [
            'status' => PrintJob::STATUS_PROCESSING,
            'printer_key' => 'kitchen',
            'locked_until' => now()->addMinute(),
        ]);
        $this->job('failed-bar', [
            'status' => PrintJob::STATUS_FAILED,
            'printer_key' => 'bar',
            'attempts' => 3,
            'max_attempts' => 3,
            'failed_at' => now(),
            'last_error' => 'Paper out.',
            'print_station_id' => $station->id,
        ]);
        $this->job('printed-biller', [
            'status' => PrintJob::STATUS_PRINTED,
            'printer_key' => 'biller',
            'printed_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->get(route('admin.print-stations.index'));

        $response
            ->assertOk()
            ->assertViewHas('queueCounts', [
                'pending' => 1,
                'processing' => 1,
                'failed' => 1,
                'printed_today' => 1,
                'exhausted' => 1,
            ])
            ->assertSee('Printed Today')
            ->assertSee('Attempt history')
            ->assertSee('Customer');
    }

    public function test_station_operational_status_rules(): void
    {
        config()->set('pos.printing.station_offline_after_seconds', 90);

        $disabled = $this->station(['counter' => 'COUNTER'], ['enabled' => false]);
        $misconfigured = $this->station([], ['name' => 'Empty map']);
        $never = $this->station(['counter' => 'COUNTER'], ['name' => 'Never']);
        $healthy = $this->station(['counter' => 'COUNTER'], [
            'name' => 'Healthy',
            'last_seen_at' => now()->subSeconds(10),
        ]);
        $warning = $this->station(['counter' => 'COUNTER'], [
            'name' => 'Warning',
            'last_seen_at' => now()->subSeconds(10),
            'last_error' => 'Paper low.',
        ]);
        $offline = $this->station(['counter' => 'COUNTER'], [
            'name' => 'Offline',
            'last_seen_at' => now()->subMinutes(3),
        ]);

        $this->assertSame('Disabled', $disabled->operationalStatus());
        $this->assertSame('Misconfigured', $misconfigured->operationalStatus());
        $this->assertSame('Never Connected', $never->operationalStatus());
        $this->assertSame('Online / Healthy', $healthy->operationalStatus());
        $this->assertSame('Online / Warning', $warning->operationalStatus());
        $this->assertSame('Online / Warning', $healthy->operationalStatus(true));
        $this->assertSame('Offline', $offline->operationalStatus());
    }

    public function test_unclaimable_detection_and_filter_include_only_reclaimable_unmapped_jobs(): void
    {
        $admin = User::factory()->create(['category_id' => UserRole::Admin]);
        $this->station(['counter' => 'COUNTER']);
        $unclaimable = $this->job('unclaimable-kitchen', [
            'status' => PrintJob::STATUS_PENDING,
            'printer_key' => 'kitchen',
        ]);
        $this->job('mapped-counter', [
            'status' => PrintJob::STATUS_PENDING,
            'printer_key' => 'counter',
        ]);
        $this->job('active-unmapped-processing', [
            'status' => PrintJob::STATUS_PROCESSING,
            'printer_key' => 'bar',
            'locked_until' => now()->addMinute(),
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->get(route('admin.print-stations.index', [
                'unclaimable_only' => 1,
            ]));

        $response
            ->assertOk()
            ->assertSee('UNCLAIMABLE')
            ->assertSee('Add this printer key mapping to an enabled print station.')
            ->assertViewHas('jobs', function ($jobs) use ($unclaimable) {
                return $jobs->getCollection()->pluck('id')->all() === [$unclaimable->id];
            });
    }

    public function test_filters_by_status_key_copy_station_and_date(): void
    {
        $admin = User::factory()->create(['category_id' => UserRole::Admin]);
        $station = $this->station(['counter' => 'COUNTER']);
        $matching = $this->job('matching-job', [
            'status' => PrintJob::STATUS_FAILED,
            'printer_key' => 'counter',
            'copy_type' => 'restaurant',
            'type' => 'bill',
            'print_station_id' => $station->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->job('other-job', [
            'status' => PrintJob::STATUS_PENDING,
            'printer_key' => 'kitchen',
            'copy_type' => 'customer',
            'type' => 'kot',
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->get(route('admin.print-stations.index', [
                'status' => 'failed',
                'printer_key' => 'counter',
                'copy_type' => 'restaurant',
                'station' => $station->id,
                'type' => 'bill',
                'date_from' => now()->subDay()->toDateString(),
                'date_to' => now()->addDay()->toDateString(),
            ]));

        $response->assertOk()->assertViewHas('jobs', function ($jobs) use ($matching) {
            return $jobs->getCollection()->pluck('id')->all() === [$matching->id];
        });
    }

    public function test_attempt_history_and_retry_visibility_are_rendered(): void
    {
        $admin = User::factory()->create(['category_id' => UserRole::Admin]);
        $station = $this->station(['counter' => 'COUNTER']);
        $failed = $this->job('failed-with-attempt', [
            'status' => PrintJob::STATUS_FAILED,
            'printer_key' => 'counter',
            'failed_at' => now(),
            'last_error' => 'Printer offline.',
        ]);
        $failed->printAttempts()->create([
            'attempt_number' => 1,
            'print_station_id' => $station->id,
            'printer_name' => 'COUNTER',
            'status' => PrintAttempt::STATUS_FAILED,
            'claimed_at' => now()->subMinute(),
            'completed_at' => now(),
            'error' => 'Printer offline.',
        ]);
        $this->job('pending-no-retry', [
            'status' => PrintJob::STATUS_PENDING,
            'printer_key' => 'counter',
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->get(route('admin.print-stations.index'));

        $response
            ->assertOk()
            ->assertSee('Attempt 1')
            ->assertSee('Printer offline.')
            ->assertSee('Step-up confirmation is required.')
            ->assertSee('Only failed jobs are eligible.');
    }

    private function station(array $printerMap, array $attributes = []): PrintStation
    {
        return PrintStation::create(array_merge([
            'name' => 'Reception',
            'token_hash' => PrintStation::hashToken('token-' . uniqid()),
            'enabled' => true,
            'printer_map' => $printerMap,
        ], $attributes));
    }

    private function job(string $key, array $attributes = []): PrintJob
    {
        return PrintJob::create(array_merge([
            'idempotency_key' => $key,
            'type' => 'kot',
            'printer_key' => 'kitchen',
            'payload_format' => 'escpos_base64',
            'payload' => base64_encode('test'),
            'status' => PrintJob::STATUS_PENDING,
        ], $attributes));
    }
}

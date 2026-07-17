<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Bill;
use App\Models\PrintAttempt;
use App\Models\PrintJob;
use App\Models\PrintStation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrintStationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_station_can_pull_and_mark_job_printed(): void
    {
        $token = 'ps_test_token';
        $station = PrintStation::create([
            'name' => 'Reception PC',
            'token_hash' => PrintStation::hashToken($token),
            'printer_map' => ['kitchen' => 'KITCHEN_KOT'],
            'enabled' => true,
        ]);

        $job = PrintJob::create([
            'idempotency_key' => 'order:1:kitchen:KOT',
            'type' => 'kot',
            'printer_key' => 'kitchen',
            'payload_format' => 'escpos_base64',
            'payload' => base64_encode('test-print-data'),
            'status' => PrintJob::STATUS_PENDING,
        ]);

        $this
            ->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/print-station/jobs?limit=5')
            ->assertOk()
            ->assertJsonPath('jobs.0.id', $job->id)
            ->assertJsonPath('jobs.0.printer_key', 'kitchen');

        $this->assertDatabaseHas('print_jobs', [
            'id' => $job->id,
            'status' => PrintJob::STATUS_PROCESSING,
            'attempts' => 1,
            'print_station_id' => $station->id,
        ]);
        $this->assertDatabaseHas('print_attempts', [
            'print_job_id' => $job->id,
            'attempt_number' => 1,
            'print_station_id' => $station->id,
            'printer_name' => 'KITCHEN_KOT',
            'status' => PrintAttempt::STATUS_PROCESSING,
        ]);
        $this->assertNotNull($job->fresh()->last_attempted_at);

        $this
            ->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/print-station/jobs/{$job->id}/printed")
            ->assertOk();

        $this->assertDatabaseHas('print_jobs', [
            'id' => $job->id,
            'status' => PrintJob::STATUS_PRINTED,
            'last_error' => null,
        ]);
        $attempt = PrintAttempt::where('print_job_id', $job->id)->sole();
        $this->assertSame(PrintAttempt::STATUS_PRINTED, $attempt->status);
        $this->assertNotNull($attempt->completed_at);
    }

    public function test_invalid_station_token_is_rejected(): void
    {
        $this
            ->withHeader('Authorization', 'Bearer bad-token')
            ->getJson('/api/print-station/jobs')
            ->assertUnauthorized();
    }

    public function test_missing_station_token_is_rejected(): void
    {
        $this
            ->getJson('/api/print-station/jobs')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Print station token is required.');
    }

    public function test_station_bearer_token_only_works_when_service_auth_is_disabled_by_default(): void
    {
        config()->set('security.print_service.authentication_required', false);
        $token = 'ps_default_bearer_token';

        PrintStation::create([
            'name' => 'Counter',
            'token_hash' => PrintStation::hashToken($token),
            'printer_map' => ['counter' => 'COUNTER'],
            'enabled' => true,
        ]);

        $this
            ->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/print-station/heartbeat')
            ->assertOk();
    }

    public function test_station_only_receives_jobs_for_mapped_printers(): void
    {
        $token = 'ps_test_token';
        PrintStation::create([
            'name' => 'Reception PC',
            'token_hash' => PrintStation::hashToken($token),
            'printer_map' => ['kitchen' => 'KITCHEN_KOT'],
            'enabled' => true,
        ]);

        PrintJob::create([
            'idempotency_key' => 'order:1:bar:BOT',
            'type' => 'bot',
            'printer_key' => 'bar',
            'payload_format' => 'escpos_base64',
            'payload' => base64_encode('bar-print-data'),
            'status' => PrintJob::STATUS_PENDING,
        ]);

        $this
            ->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/print-station/jobs?limit=5')
            ->assertOk()
            ->assertJsonCount(0, 'jobs');
    }

    public function test_station_cannot_complete_job_locked_by_another_station(): void
    {
        $firstToken = 'ps_first_token';
        $secondToken = 'ps_second_token';

        $firstStation = PrintStation::create([
            'name' => 'Reception PC',
            'token_hash' => PrintStation::hashToken($firstToken),
            'printer_map' => ['kitchen' => 'KITCHEN_KOT'],
            'enabled' => true,
        ]);

        PrintStation::create([
            'name' => 'Backup PC',
            'token_hash' => PrintStation::hashToken($secondToken),
            'printer_map' => ['kitchen' => 'KITCHEN_KOT'],
            'enabled' => true,
        ]);

        $job = PrintJob::create([
            'idempotency_key' => 'order:2:kitchen:KOT',
            'type' => 'kot',
            'printer_key' => 'kitchen',
            'payload_format' => 'escpos_base64',
            'payload' => base64_encode('test-print-data'),
            'status' => PrintJob::STATUS_PROCESSING,
            'print_station_id' => $firstStation->id,
            'locked_until' => now()->addMinute(),
        ]);

        $this
            ->withHeader('Authorization', "Bearer {$secondToken}")
            ->postJson("/api/print-station/jobs/{$job->id}/printed")
            ->assertStatus(409);

        $this->assertDatabaseHas('print_jobs', [
            'id' => $job->id,
            'status' => PrintJob::STATUS_PROCESSING,
            'print_station_id' => $firstStation->id,
            'printed_at' => null,
        ]);
    }

    public function test_bill_is_only_marked_physically_printed_after_station_acknowledgement(): void
    {
        $token = 'ps_counter_token';
        $station = PrintStation::create([
            'name' => 'Reception PC',
            'token_hash' => PrintStation::hashToken($token),
            'printer_map' => ['counter' => 'RECEIPT'],
            'enabled' => true,
        ]);
        $bill = Bill::create([
            'bill_id' => 20260619001,
            'bill_amount' => 100,
            'discount' => 0,
            'grand_total' => 100,
            'status' => 'open',
            'payment_method' => 'cash',
            'locked_at' => now(),
        ]);
        $job = PrintJob::create([
            'idempotency_key' => "bill:{$bill->id}:counter",
            'type' => 'bill',
            'printer_key' => 'counter',
            'source_type' => Bill::class,
            'source_id' => $bill->id,
            'payload_format' => 'escpos_base64',
            'payload' => base64_encode('bill-data'),
            'status' => PrintJob::STATUS_PROCESSING,
            'print_station_id' => $station->id,
            'locked_until' => now()->addMinute(),
        ]);

        $this->assertNull($bill->printed_at);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/print-station/jobs/{$job->id}/printed")
            ->assertOk();

        $this->assertNotNull($bill->fresh()->printed_at);
    }

    public function test_failed_acknowledgement_completes_active_attempt_and_records_error(): void
    {
        [$station, $token] = $this->stationWithToken();
        $job = $this->pendingJob('order:failed:kitchen');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/print-station/jobs')
            ->assertOk();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/print-station/jobs/{$job->id}/failed", [
                'error' => 'Printer is offline.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('print_jobs', [
            'id' => $job->id,
            'status' => PrintJob::STATUS_FAILED,
            'last_error' => 'Printer is offline.',
        ]);
        $attempt = PrintAttempt::where('print_job_id', $job->id)->sole();
        $this->assertSame(PrintAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame('Printer is offline.', $attempt->error);
        $this->assertNotNull($attempt->completed_at);
        $this->assertSame('Printer is offline.', $station->fresh()->last_error);
    }

    public function test_expired_processing_attempt_is_expired_before_job_is_reclaimed(): void
    {
        [$station, $token] = $this->stationWithToken();
        $job = PrintJob::create([
            'idempotency_key' => 'order:expired:kitchen',
            'type' => 'kot',
            'printer_key' => 'kitchen',
            'payload_format' => 'escpos_base64',
            'payload' => base64_encode('test-print-data'),
            'status' => PrintJob::STATUS_PROCESSING,
            'attempts' => 1,
            'max_attempts' => 3,
            'print_station_id' => $station->id,
            'locked_until' => now()->subMinute(),
        ]);
        $oldAttempt = $job->printAttempts()->create([
            'attempt_number' => 1,
            'print_station_id' => $station->id,
            'printer_name' => 'KITCHEN_KOT',
            'status' => PrintAttempt::STATUS_PROCESSING,
            'claimed_at' => now()->subMinutes(3),
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/print-station/jobs')
            ->assertOk()
            ->assertJsonPath('jobs.0.attempts', 2);

        $this->assertSame(PrintAttempt::STATUS_EXPIRED, $oldAttempt->fresh()->status);
        $this->assertNotNull($oldAttempt->fresh()->completed_at);
        $this->assertDatabaseHas('print_attempts', [
            'print_job_id' => $job->id,
            'attempt_number' => 2,
            'status' => PrintAttempt::STATUS_PROCESSING,
        ]);
    }

    public function test_exhausted_expired_job_is_failed_and_not_reclaimed(): void
    {
        [$station, $token] = $this->stationWithToken();
        $job = PrintJob::create([
            'idempotency_key' => 'order:exhausted:kitchen',
            'type' => 'kot',
            'printer_key' => 'kitchen',
            'payload_format' => 'escpos_base64',
            'payload' => base64_encode('test-print-data'),
            'status' => PrintJob::STATUS_PROCESSING,
            'attempts' => 3,
            'max_attempts' => 3,
            'print_station_id' => $station->id,
            'locked_until' => now()->subMinute(),
        ]);
        $attempt = $job->printAttempts()->create([
            'attempt_number' => 3,
            'print_station_id' => $station->id,
            'printer_name' => 'KITCHEN_KOT',
            'status' => PrintAttempt::STATUS_PROCESSING,
            'claimed_at' => now()->subMinutes(3),
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/print-station/jobs')
            ->assertOk()
            ->assertJsonCount(0, 'jobs');

        $job->refresh();
        $this->assertSame(PrintJob::STATUS_FAILED, $job->status);
        $this->assertStringContainsString('maximum of 3 attempts', $job->last_error);
        $this->assertSame(PrintAttempt::STATUS_EXPIRED, $attempt->fresh()->status);
        $this->assertSame(3, $job->attempts);
        $this->assertCount(1, $job->printAttempts);
    }

    public function test_manual_retry_only_works_for_failed_jobs_and_requires_step_up(): void
    {
        $admin = User::factory()->create(['category_id' => UserRole::Admin]);
        $pendingJob = $this->pendingJob('order:pending:kitchen');

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true, 'auth.step_up_at' => time()])
            ->post(route('admin.print-jobs.retry', $pendingJob))
            ->assertStatus(409);

        $failedJob = $this->failedJob('order:step-up:kitchen');

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true, 'auth.step_up_at' => 0])
            ->post(route('admin.print-jobs.retry', $failedJob))
            ->assertRedirect(route('password.confirm'));

        $this->assertSame(PrintJob::STATUS_FAILED, $failedJob->fresh()->status);
    }

    public function test_manual_retry_records_actor_time_and_grants_one_more_attempt_without_changing_payload(): void
    {
        $admin = User::factory()->create(['category_id' => UserRole::Admin]);
        $job = $this->failedJob('order:manual-retry:kitchen');
        $originalPayload = $job->payload;
        $job->printAttempts()->create([
            'attempt_number' => 3,
            'status' => PrintAttempt::STATUS_FAILED,
            'completed_at' => now(),
            'error' => 'Paper out.',
        ]);

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true, 'auth.step_up_at' => time()])
            ->post(route('admin.print-jobs.retry', $job))
            ->assertRedirect(route('admin.print-stations.index'));

        $job->refresh();
        $this->assertSame(PrintJob::STATUS_PENDING, $job->status);
        $this->assertSame(4, $job->max_attempts);
        $this->assertSame($admin->id, $job->retried_by);
        $this->assertNotNull($job->retried_at);
        $this->assertSame($originalPayload, $job->payload);
        $this->assertNull($job->print_station_id);
        $this->assertNull($job->locked_until);
        $this->assertCount(1, $job->printAttempts);
    }

    public function test_repeated_printed_acknowledgement_is_idempotent(): void
    {
        [, $token] = $this->stationWithToken();
        $job = $this->pendingJob('order:idempotent:kitchen');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/print-station/jobs')
            ->assertOk();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/print-station/jobs/{$job->id}/printed")
            ->assertOk();
        $printedAt = $job->fresh()->printed_at;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/print-station/jobs/{$job->id}/printed")
            ->assertOk();

        $this->assertTrue($printedAt->equalTo($job->fresh()->printed_at));
        $this->assertSame(PrintAttempt::STATUS_PRINTED, $job->printAttempts()->sole()->status);
        $this->assertSame(1, $job->printAttempts()->count());
    }

    public function test_legacy_processing_job_with_null_audit_fields_can_still_be_acknowledged(): void
    {
        [$station, $token] = $this->stationWithToken();
        $job = PrintJob::create([
            'idempotency_key' => 'order:legacy:kitchen',
            'type' => 'kot',
            'printer_key' => 'kitchen',
            'payload_format' => 'escpos_base64',
            'payload' => base64_encode('legacy-data'),
            'status' => PrintJob::STATUS_PROCESSING,
            'attempts' => 1,
            'print_station_id' => $station->id,
            'locked_until' => now()->addMinute(),
            'last_attempted_at' => null,
            'retried_by' => null,
            'retried_at' => null,
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/print-station/jobs/{$job->id}/printed")
            ->assertOk();

        $this->assertSame(PrintJob::STATUS_PRINTED, $job->fresh()->status);
        $this->assertSame(0, $job->printAttempts()->count());
    }

    private function stationWithToken(): array
    {
        $token = 'ps_' . uniqid();
        $station = PrintStation::create([
            'name' => 'Reception PC',
            'token_hash' => PrintStation::hashToken($token),
            'printer_map' => ['kitchen' => 'KITCHEN_KOT'],
            'enabled' => true,
        ]);

        return [$station, $token];
    }

    private function pendingJob(string $idempotencyKey): PrintJob
    {
        return PrintJob::create([
            'idempotency_key' => $idempotencyKey,
            'type' => 'kot',
            'printer_key' => 'kitchen',
            'payload_format' => 'escpos_base64',
            'payload' => base64_encode('test-print-data'),
            'status' => PrintJob::STATUS_PENDING,
        ]);
    }

    private function failedJob(string $idempotencyKey): PrintJob
    {
        return PrintJob::create([
            'idempotency_key' => $idempotencyKey,
            'type' => 'kot',
            'printer_key' => 'kitchen',
            'payload_format' => 'escpos_base64',
            'payload' => base64_encode('test-print-data'),
            'status' => PrintJob::STATUS_FAILED,
            'attempts' => 3,
            'max_attempts' => 3,
            'failed_at' => now(),
            'last_error' => 'Paper out.',
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\PrintJob;
use App\Models\PrintStation;
use App\Models\User;
use App\Services\Operations\SystemStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class OperationsPhaseThreeATest extends TestCase
{
    use RefreshDatabase;

    public function test_status_page_requires_auth_and_admin_access(): void
    {
        $this->get(route('admin.system.status'))->assertRedirect();

        $biller = User::factory()->create(['category_id' => UserRole::Biller]);
        $this->actingAs($biller)
            ->get(route('admin.system.status'))
            ->assertForbidden();

        $admin = User::factory()->create(['category_id' => UserRole::Admin]);
        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->get(route('admin.system.status'))
            ->assertOk()
            ->assertSee('System Status');
    }

    public function test_status_page_does_not_expose_secrets(): void
    {
        config()->set('app.key', 'must-not-appear-in-html-12345678');
        config()->set('database.connections.mysql.password', 'secret-db-password');
        config()->set('broadcasting.connections.pusher.secret', 'secret-soketi-value');
        config()->set('services.cbms.username', 'secret-cbms-user');
        config()->set('services.cbms.password', 'secret-cbms-password');

        $admin = User::factory()->create(['category_id' => UserRole::Admin]);

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->get(route('admin.system.status'))
            ->assertOk()
            ->assertDontSee('must-not-appear-in-html')
            ->assertDontSee('secret-db-password')
            ->assertDontSee('secret-soketi-value')
            ->assertDontSee('secret-cbms-user')
            ->assertDontSee('secret-cbms-password');
    }

    public function test_backup_validation_rejects_missing_file(): void
    {
        $this->artisan('backup:validate', ['file' => storage_path('missing-backup.sql.gz')])
            ->assertExitCode(1);
    }

    public function test_production_image_includes_the_database_backup_client(): void
    {
        $this->assertStringContainsString(
            'RUN apk add --no-cache mariadb-client mariadb-connector-c',
            file_get_contents(base_path('Dockerfile'))
        );
    }

    public function test_backup_retention_dry_run_does_not_delete_files(): void
    {
        $directory = storage_path('framework/testing/backups-retention');
        File::deleteDirectory($directory);
        File::ensureDirectoryExists($directory);
        config()->set('operations.backup.storage_path', $directory);
        config()->set('operations.backup.retention.daily', 0);
        config()->set('operations.backup.retention.weekly', 0);
        config()->set('operations.backup.retention.monthly', 0);

        $file = $directory . DIRECTORY_SEPARATOR . 'restaurant_pos_20260101_010101_test.sql.gz';
        file_put_contents($file, gzencode('select 1;'));
        touch($file, now()->subDays(30)->timestamp);

        $this->artisan('backup:prune', ['--dry-run' => true])
            ->assertExitCode(0);

        $this->assertFileExists($file);
    }

    public function test_health_command_json_contains_expected_keys(): void
    {
        Artisan::call('operations:status', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('app', $payload);
        $this->assertArrayHasKey('database', $payload);
        $this->assertArrayHasKey('queue', $payload);
        $this->assertArrayHasKey('scheduler', $payload);
        $this->assertArrayHasKey('clock', $payload);
        $this->assertArrayHasKey('cbms', $payload);
        $this->assertArrayHasKey('backup', $payload);
        $this->assertArrayHasKey('printing', $payload);
    }

    public function test_cbms_failures_and_aged_submissions_use_existing_operations_alerts(): void
    {
        config()->set('operations.monitoring.cbms_pending_max_minutes', 15);
        $findings = app(SystemStatusService::class)->alertFindings([
            'scheduler' => ['ok' => true],
            'clock' => ['ok' => true],
            'cbms' => ['enabled' => true, 'failed' => 2, 'oldest_outstanding_age_minutes' => 16],
            'queue' => ['failed_jobs_count' => 0],
            'backup' => ['latest' => ['age_minutes' => 0]],
            'disk' => ['used_percent' => 0],
            'printing' => [],
        ]);

        $this->assertSame(['cbms_failed', 'cbms_pending'], array_column($findings, 'key'));
    }

    public function test_alert_command_logs_warnings_without_smtp_configuration(): void
    {
        config()->set('operations.monitoring.alert_email', null);
        config()->set('operations.monitoring.failed_job_threshold', -1);
        config()->set('operations.monitoring.backup_max_age_hours', 0);

        $this->artisan('operations:check-alerts')
            ->assertExitCode(0);
    }

    public function test_print_summary_handles_empty_and_populated_data(): void
    {
        $service = app(SystemStatusService::class);

        $empty = $service->printingStatus();
        $this->assertSame(0, $empty['stations_total']);
        $this->assertSame(0, $empty['pending_jobs']);

        PrintStation::create([
            'name' => 'Counter',
            'token_hash' => PrintStation::hashToken('token'),
            'enabled' => true,
            'printer_map' => ['counter' => 'COUNTER'],
            'last_seen_at' => now()->subMinutes(10),
        ]);

        PrintJob::create([
            'idempotency_key' => 'unmapped-pending',
            'type' => 'kot',
            'printer_key' => 'kitchen',
            'payload_format' => 'escpos_base64',
            'payload' => base64_encode('test'),
            'status' => PrintJob::STATUS_PENDING,
        ]);

        PrintJob::create([
            'idempotency_key' => 'failed-exhausted',
            'type' => 'bill',
            'printer_key' => 'counter',
            'payload_format' => 'escpos_base64',
            'payload' => base64_encode('test'),
            'status' => PrintJob::STATUS_FAILED,
            'attempts' => 3,
            'max_attempts' => 3,
        ]);

        $summary = $service->printingStatus();

        $this->assertSame(1, $summary['stations_total']);
        $this->assertSame(1, $summary['offline_stations']);
        $this->assertSame(1, $summary['pending_jobs']);
        $this->assertSame(1, $summary['failed_jobs']);
        $this->assertSame(1, $summary['exhausted_jobs']);
        $this->assertSame(1, $summary['unclaimable_jobs']);
    }
}

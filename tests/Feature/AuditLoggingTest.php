<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\Bill;
use App\Models\EmployeeCategory;
use App\Models\Menu;
use App\Models\PrintStation;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use App\Modules\Inventory\Models\StockCategory;
use App\Modules\Inventory\Models\StockItem;
use App\Services\AuditLogger;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuditLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_events_migration_model_and_logger_work(): void
    {
        $this->assertTrue(Schema::hasTable('audit_events'));
        $this->assertTrue(Schema::hasColumn('audit_events', 'event_type'));
        $this->assertTrue(Schema::hasColumn('audit_events', 'before_values'));

        $user = User::factory()->create(['category_id' => UserRole::Admin]);

        $event = app(AuditLogger::class)->record('audit_test_written', 'diagnostics', [
            'user' => $user,
            'subject' => $user,
            'metadata' => ['summary' => 'Audit logger wrote an event.'],
        ]);

        $this->assertInstanceOf(AuditEvent::class, $event);
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'audit_test_written',
            'category' => 'diagnostics',
            'user_id' => $user->id,
            'subject_type' => User::class,
            'subject_id' => $user->id,
        ]);
    }

    public function test_audit_logger_redacts_sensitive_values(): void
    {
        $event = app(AuditLogger::class)->record('secret_redaction_test', 'diagnostics', [
            'metadata' => [
                'password' => 'plain-password',
                'password_confirmation' => 'plain-password',
                'remember_token' => 'remember-me',
                'APP_KEY' => 'base64:app-key',
                'DB_PASSWORD' => 'db-secret',
                'PUSHER_APP_SECRET' => 'pusher-secret',
                'PRINT_SERVICE_CLIENT_SECRET' => 'print-secret',
                'print_station_token' => 'ps_plain_token',
                'csrf_token' => 'csrf-value',
                'Authorization' => 'Bearer bearer-token',
                'session_payload' => 'serialized-session',
                'id' => 123,
                'user_id' => 456,
            ],
        ]);

        $metadata = $event->metadata;
        $this->assertSame('[REDACTED]', $metadata['password']);
        $this->assertSame('[REDACTED]', $metadata['password_confirmation']);
        $this->assertSame('[REDACTED]', $metadata['remember_token']);
        $this->assertSame('[REDACTED]', $metadata['APP_KEY']);
        $this->assertSame('[REDACTED]', $metadata['DB_PASSWORD']);
        $this->assertSame('[REDACTED]', $metadata['PUSHER_APP_SECRET']);
        $this->assertSame('[REDACTED]', $metadata['PRINT_SERVICE_CLIENT_SECRET']);
        $this->assertSame('[REDACTED]', $metadata['print_station_token']);
        $this->assertSame('[REDACTED]', $metadata['csrf_token']);
        $this->assertSame('[REDACTED]', $metadata['Authorization']);
        $this->assertSame('[REDACTED]', $metadata['session_payload']);
        $this->assertSame(123, $metadata['id']);
        $this->assertSame(456, $metadata['user_id']);
        $this->assertStringNotContainsString('plain-password', json_encode($metadata));
        $this->assertStringNotContainsString('ps_plain_token', json_encode($metadata));
    }

    public function test_audit_logger_failure_does_not_crash_by_default(): void
    {
        Log::shouldReceive('error')->once();

        $badPayload = new class {
            public function toArray(): array
            {
                throw new \RuntimeException('redaction failed');
            }
        };

        $event = app(AuditLogger::class)->record('will_fail', 'diagnostics', [
            'metadata' => $badPayload,
        ]);

        $this->assertNull($event);
    }

    public function test_audit_page_access_control_and_owner_admin_access(): void
    {
        $this->get(route('admin.audit-events.index'))->assertRedirect();

        $biller = User::factory()->create(['category_id' => UserRole::Biller]);
        $this->actingAs($biller)
            ->withSession(['auth.mfa_passed' => true])
            ->get(route('admin.audit-events.index'))
            ->assertForbidden();

        $admin = User::factory()->create(['category_id' => UserRole::Admin]);
        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->get(route('admin.audit-events.index'))
            ->assertOk()
            ->assertSee('Audit Events');

        $owner = User::factory()->create(['category_id' => UserRole::Owner]);
        $this->actingAs($owner)
            ->withSession(['auth.mfa_passed' => true])
            ->get(route('admin.audit-events.index'))
            ->assertOk();
    }

    public function test_audit_page_does_not_expose_redacted_secret_values(): void
    {
        $admin = User::factory()->create(['category_id' => UserRole::Admin]);

        app(AuditLogger::class)->record('secret_ui_test', 'diagnostics', [
            'metadata' => [
                'summary' => 'Secret UI test',
                'password' => 'visible-password-should-not-render',
                'api_token' => 'visible-token-should-not-render',
            ],
        ]);

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->get(route('admin.audit-events.index'))
            ->assertOk()
            ->assertSee('Secret UI test')
            ->assertSee('[REDACTED]')
            ->assertDontSee('visible-password-should-not-render')
            ->assertDontSee('visible-token-should-not-render');
    }

    public function test_audit_filters_work(): void
    {
        $admin = User::factory()->create(['category_id' => UserRole::Admin]);
        $matching = AuditEvent::create([
            'event_type' => 'login_failure',
            'category' => 'authentication',
            'severity' => 'warning',
            'user_id' => $admin->id,
            'subject_type' => User::class,
            'subject_id' => $admin->id,
            'metadata' => ['summary' => 'Needle event'],
            'created_at' => now(),
        ]);
        AuditEvent::create([
            'event_type' => 'stock_item_created',
            'category' => 'inventory',
            'severity' => 'info',
            'metadata' => ['summary' => 'Other event'],
            'created_at' => now()->subDays(2),
        ]);

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->get(route('admin.audit-events.index', [
                'date_from' => now()->subDay()->toDateString(),
                'date_to' => now()->addDay()->toDateString(),
                'category' => 'authentication',
                'event_type' => 'login_failure',
                'severity' => 'warning',
                'user_id' => $admin->id,
                'subject_type' => User::class,
                'subject_id' => $admin->id,
                'search' => 'Needle',
            ]))
            ->assertOk()
            ->assertViewHas('events', function ($events) use ($matching) {
                return $events->getCollection()->pluck('id')->all() === [$matching->id];
            })
            ->assertSee('Needle event')
            ->assertDontSee('Other event');
    }

    public function test_user_create_update_and_deactivate_write_audit_events(): void
    {
        $admin = User::factory()->create(['category_id' => UserRole::Admin]);
        $this->ensureEmployeeCategory(UserRole::Biller);
        $this->ensureEmployeeCategory(UserRole::Manager);

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true, 'auth.step_up_at' => time()])
            ->post(route('admin.users.store'), [
                'name' => 'Audit User',
                'username' => 'audit-user',
                'email' => 'audit-user@example.test',
                'password' => 'SecurePassword123',
                'password_confirmation' => 'SecurePassword123',
                'category_id' => UserRole::Biller->value,
                'is_active' => true,
            ])
            ->assertRedirect(route('admin.users.index'));

        $user = User::where('username', 'audit-user')->firstOrFail();

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true, 'auth.step_up_at' => time()])
            ->put(route('admin.users.update', $user), [
                'name' => 'Audit User Updated',
                'username' => 'audit-user',
                'email' => 'audit-user-updated@example.test',
                'category_id' => UserRole::Manager->value,
                'is_active' => true,
            ])
            ->assertRedirect(route('admin.users.index'));

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true, 'auth.step_up_at' => time()])
            ->delete(route('admin.users.destroy', $user))
            ->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseHas('audit_events', ['event_type' => 'user_created', 'subject_type' => User::class, 'subject_id' => $user->id]);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'user_updated', 'subject_type' => User::class, 'subject_id' => $user->id]);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'user_role_changed', 'subject_type' => User::class, 'subject_id' => $user->id]);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'user_deactivated', 'subject_type' => User::class, 'subject_id' => $user->id]);
    }

    public function test_restaurant_config_and_module_changes_write_audit_events(): void
    {
        $admin = User::factory()->create(['category_id' => UserRole::Admin]);
        Restaurant::create([
            'name' => 'Restaurant POS',
            'tagline' => 'Fresh',
            'address' => 'Kathmandu',
            'phone' => '123456',
            'pending_order_sync_time' => 15,
            'waiter_sync_time' => 15,
            'minimum_delivery_time' => 300,
            'minimum_preparation_time' => 300,
            'order_live_view' => 'asc',
            'kot_live_view' => 'asc',
            'payment_options' => json_encode(['cash' => true]),
            'tax_rate' => 13,
            'currency_symbol' => 'NPR',
            'reservation_advance_notice' => 60,
            'waiter_module_enabled' => false,
            'kitchen_module_enabled' => false,
        ]);
        config()->set('broadcasting.connections.pusher.key', 'key');
        config()->set('broadcasting.connections.pusher.secret', 'secret');
        config()->set('broadcasting.connections.pusher.app_id', 'app');
        config()->set('broadcasting.connections.pusher.options.cluster', 'mt1');

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true, 'auth.step_up_at' => time()])
            ->post(route('admin.restaurant.update.config'), [
                'name' => 'Audit Test Restaurant',
                'tagline' => 'Fresh',
                'address' => 'Kathmandu',
                'phone' => '123456',
                'GST' => 'PAN-1',
                'email' => 'audit@example.test',
                'website' => 'https://example.test',
                'pending_order_sync_time' => '15',
                'waiter_sync_time' => '15',
                'minimum_delivery_time' => 300,
                'minimum_preparation_time' => 300,
                'order_live_view' => 'asc',
                'kot_live_view' => 'asc',
            ])
            ->assertRedirect(route('admin.restaurant.show.config'));

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true, 'auth.step_up_at' => time()])
            ->post(route('admin.restaurant.enable_waiter_module'))
            ->assertOk();

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true, 'auth.step_up_at' => time()])
            ->post(route('admin.restaurant.enable_kitchen_module'))
            ->assertOk();

        $this->assertDatabaseHas('audit_events', ['event_type' => 'restaurant_config_updated', 'category' => 'configuration']);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'waiter_module_enabled', 'category' => 'configuration']);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'kitchen_module_enabled', 'category' => 'configuration']);
    }

    public function test_bill_finalization_writes_audit_event(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('category_id', UserRole::Admin->value)->firstOrFail();
        $table = Table::where('name', 'T1')->firstOrFail();
        $menu = Menu::where('shortcode', 'mc')->firstOrFail();

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->postJson(route('order.submit'), [
                'source' => 'pos',
                'tableId' => $table->id,
                'specialInstructions' => [],
                'isPickUpOrder' => 'false',
                'paymentMethod' => 'cash',
                'billTable' => 'false',
                'order' => [
                    'orderItems' => [['id' => $menu->id, 'quantity' => 1]],
                ],
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->postJson(route('pos.table.bill'), [
                'tableId' => $table->id,
                'billAction' => 'final',
                'billingSource' => 'all',
                'paymentType' => 'cash',
            ])
            ->assertOk();

        $bill = Bill::firstOrFail();
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'bill_finalized',
            'category' => 'billing',
            'subject_type' => Bill::class,
            'subject_id' => $bill->id,
        ]);
    }

    public function test_print_station_token_regeneration_writes_audit_without_token(): void
    {
        $admin = User::factory()->create(['category_id' => UserRole::Admin]);
        $station = PrintStation::create([
            'name' => 'Counter',
            'token_hash' => PrintStation::hashToken('old-token'),
            'enabled' => true,
            'printer_map' => ['counter' => 'COUNTER'],
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true, 'auth.step_up_at' => time()])
            ->post(route('admin.print-stations.regenerate-token', $station));

        $response->assertRedirect(route('admin.print-stations.index'));
        $plainToken = session('print_station_token');
        $this->assertNotEmpty($plainToken);

        $event = AuditEvent::where('event_type', 'print_station_token_regenerated')->sole();
        $this->assertSame(PrintStation::class, $event->subject_type);
        $this->assertSame($station->id, $event->subject_id);
        $this->assertStringNotContainsString($plainToken, json_encode($event->toArray()));
    }

    public function test_inventory_stock_movement_writes_audit_event(): void
    {
        $admin = User::factory()->create(['category_id' => UserRole::Admin]);
        $category = StockCategory::create(['name' => 'Ingredients', 'slug' => 'ingredients', 'active' => true]);
        $stockItem = StockItem::create([
            'category_id' => $category->id,
            'name' => 'Coffee Beans',
            'unit' => 'kg',
            'sku' => 'COFFEE-KG',
            'current_quantity' => 5,
            'low_stock_threshold' => 1,
            'active' => true,
        ]);

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->post(route('inventory.stock-movements.store'), [
                'stock_item_id' => $stockItem->id,
                'movement_type' => 'in',
                'quantity' => 2,
                'unit_cost' => 500,
                'notes' => 'opening adjustment',
            ])
            ->assertRedirect(route('inventory.stock-movements.index'));

        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'manual_stock_movement_created',
            'category' => 'inventory',
        ]);
    }

    private function ensureEmployeeCategory(UserRole $role): void
    {
        EmployeeCategory::unguarded(fn () => EmployeeCategory::firstOrCreate(
            ['id' => $role->value],
            ['name' => match ($role) {
                UserRole::Admin => 'Admin',
                UserRole::Waiter => 'Waiter',
                UserRole::Kitchen => 'Kitchen',
                UserRole::Biller => 'Biller',
                UserRole::Owner => 'Owner',
                UserRole::StockManager => 'Stock Manager',
                UserRole::Manager => 'Manager',
            }]
        ));
    }
}

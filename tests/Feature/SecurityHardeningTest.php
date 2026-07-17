<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Events\OrderSubmittedToKitchen;
use App\Models\Category;
use App\Models\User;
use Database\Seeders\RestaurantSeeder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_billers_cannot_delete_bills(): void
    {
        $biller = User::factory()->create(['category_id' => UserRole::Biller]);

        $this->actingAs($biller)
            ->delete('/admin/bill/999')
            ->assertForbidden();
    }

    public function test_last_administrator_cannot_demote_their_own_account(): void
    {
        $admin = User::factory()->create(['category_id' => UserRole::Admin]);
        User::factory()->create(['category_id' => UserRole::Biller]);

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true, 'auth.step_up_at' => time()])
            ->put(route('admin.users.update', $admin), [
                'name' => $admin->name,
                'username' => $admin->username,
                'email' => $admin->email,
                'category_id' => UserRole::Biller->value,
                'is_active' => '1',
            ])
            ->assertSessionHasErrors('category_id');

        $this->assertSame(UserRole::Admin, $admin->fresh()->category_id);
    }

    public function test_stock_managers_cannot_change_order_statuses(): void
    {
        $this->seed(RestaurantSeeder::class);
        $stockManager = User::factory()->create(['category_id' => UserRole::StockManager]);

        $this->actingAs($stockManager)
            ->postJson(route('order.mark.as.prepared'), ['orderId' => 999])
            ->assertForbidden();
    }

    public function test_executable_uploads_are_rejected(): void
    {
        $admin = User::factory()->create(['category_id' => UserRole::Admin]);
        $category = Category::create([
            'name' => 'Food',
            'description' => null,
            'rank' => 1,
        ]);

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->post(route('admin.menus.store'), [
                'name' => 'Unsafe item',
                'shortCode' => 'unsafe-item',
                'price' => 100,
                'category' => $category->id,
                'type' => 'service',
                'production_area' => 'kitchen',
                'image' => UploadedFile::fake()->create('shell.php', 1, 'application/x-php'),
            ])
            ->assertSessionHasErrors('image');
    }

    public function test_realtime_secret_is_not_rendered_in_admin_html(): void
    {
        Config::set('broadcasting.connections.pusher.secret', 'must-not-appear-in-html');
        $this->seed(RestaurantSeeder::class);
        $admin = User::factory()->create(['category_id' => UserRole::Admin]);

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->get(route('admin.restaurant.show.config'))
            ->assertOk()
            ->assertDontSee('must-not-appear-in-html');
    }

    public function test_kitchen_order_events_use_private_channels(): void
    {
        $channels = (new OrderSubmittedToKitchen('KOT-1'))->broadcastOn();

        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
    }
}

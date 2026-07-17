<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryRankingTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_page_uses_the_built_sortable_asset_and_handles_empty_images(): void
    {
        $admin = User::factory()->create(['category_id' => UserRole::Admin]);

        Category::create([
            'name' => 'No Image Category',
            'description' => null,
            'image' => null,
            'rank' => 1,
        ]);

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->get(route('admin.categories.index'))
            ->assertOk()
            ->assertSee('js/Sortable.min.js', false)
            ->assertSee('No image')
            ->assertDontSee('/storage/', false);
    }

    public function test_admin_can_persist_category_drag_ranking(): void
    {
        $admin = User::factory()->create(['category_id' => UserRole::Admin]);
        $first = Category::create(['name' => 'First', 'rank' => 1]);
        $second = Category::create(['name' => 'Second', 'rank' => 2]);

        $this->actingAs($admin)
            ->withSession(['auth.mfa_passed' => true])
            ->postJson(route('admin.categories.updateRanks'), [
                'updatedRankings' => [
                    ['id' => $second->id, 'rank' => 1],
                    ['id' => $first->id, 'rank' => 2],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertSame(2, $first->fresh()->rank);
        $this->assertSame(1, $second->fresh()->rank);
    }
}

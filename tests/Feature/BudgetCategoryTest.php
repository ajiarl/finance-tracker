<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BudgetCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_allows_creating_budget_with_system_category(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // 1. Create a system category (user_id = null)
        $systemCategory = Category::create([
            'user_id' => null,
            'name' => 'System Category',
            'type' => 'expense',
        ]);

        // 2. Attempt to create a budget using this system category
        $response = $this->postJson('/api/budgets', [
            'category_id' => $systemCategory->id,
            'name' => 'Test Budget',
            'amount' => 500000,
            'period' => 'monthly',
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
        ]);

        $response->assertStatus(201);
        $this->assertEquals($systemCategory->id, $response->json('data.category_id'));
    }

    public function test_it_allows_creating_budget_with_user_category(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // 1. Create a user category
        $userCategory = Category::create([
            'user_id' => $user->id,
            'name' => 'User Category',
            'type' => 'expense',
        ]);

        // 2. Attempt to create a budget using this user category
        $response = $this->postJson('/api/budgets', [
            'category_id' => $userCategory->id,
            'name' => 'Test Budget',
            'amount' => 500000,
            'period' => 'monthly',
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
        ]);

        $response->assertStatus(201);
        $this->assertEquals($userCategory->id, $response->json('data.category_id'));
    }

    public function test_it_denies_creating_budget_with_other_user_category(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($user);

        // 1. Create a category belonging to another user
        $otherCategory = Category::create([
            'user_id' => $otherUser->id,
            'name' => 'Other User Category',
            'type' => 'expense',
        ]);

        // 2. Attempt to create a budget using the other user's category
        $response = $this->postJson('/api/budgets', [
            'category_id' => $otherCategory->id,
            'name' => 'Steal Category Budget',
            'amount' => 500000,
            'period' => 'monthly',
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
        ]);

        // Should return 404 because resolveOwnedCategoryId returns null
        $response->assertStatus(404);
    }
}

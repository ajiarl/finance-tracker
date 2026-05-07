<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CategoryControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_active_system_and_user_categories_only(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $systemExpense = Category::create([
            'user_id' => null,
            'name' => 'Belanja',
            'type' => 'expense',
            'icon' => 'shopping-bag',
            'color' => '#EC4899',
            'is_active' => true,
        ]);

        $systemIncome = Category::create([
            'user_id' => null,
            'name' => 'Bonus',
            'type' => 'income',
            'icon' => 'gift',
            'color' => '#84CC16',
            'is_active' => true,
        ]);

        $userExpense = Category::create([
            'user_id' => $user->id,
            'name' => 'Jajan Kampus',
            'type' => 'expense',
            'icon' => 'coffee',
            'color' => '#F59E0B',
            'is_active' => true,
        ]);

        Category::create([
            'user_id' => $user->id,
            'name' => 'Arsip',
            'type' => 'expense',
            'icon' => 'archive',
            'color' => '#123456',
            'is_active' => false,
        ]);

        Category::create([
            'user_id' => $otherUser->id,
            'name' => 'Kategori Orang Lain',
            'type' => 'expense',
            'icon' => 'user',
            'color' => '#654321',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/categories');

        $response->assertOk();
        $response->assertJsonCount(3, 'data');
        $response->assertJsonPath('data.0.id', $systemExpense->id);
        $response->assertJsonPath('data.1.id', $systemIncome->id);
        $response->assertJsonPath('data.2.id', $userExpense->id);
    }

    public function test_store_assigns_authenticated_user_and_rejects_request_user_id_override(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/categories', [
            'user_id' => $otherUser->id,
            'name' => 'Jajan Kampus',
            'type' => 'expense',
            'icon' => 'coffee',
            'color' => '#F59E0B',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.user_id', $user->id);

        $this->assertDatabaseHas('categories', [
            'name' => 'Jajan Kampus',
            'user_id' => $user->id,
        ]);
    }

    public function test_store_and_update_require_valid_hex_color(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $storeResponse = $this->postJson('/api/categories', [
            'name' => 'Warna Salah',
            'type' => 'expense',
            'icon' => 'x',
            'color' => '1234567',
        ]);

        $storeResponse->assertStatus(422);
        $storeResponse->assertJsonValidationErrors(['color']);

        $category = Category::create([
            'user_id' => $user->id,
            'name' => 'Valid',
            'type' => 'expense',
            'icon' => 'check',
            'color' => '#F59E0B',
            'is_active' => true,
        ]);

        $updateResponse = $this->patchJson("/api/categories/{$category->id}", [
            'color' => '#ZZZZZZ',
        ]);

        $updateResponse->assertStatus(422);
        $updateResponse->assertJsonValidationErrors(['color']);
    }

    public function test_system_categories_cannot_be_updated_or_deleted(): void
    {
        $user = User::factory()->create();

        $systemCategory = Category::create([
            'user_id' => null,
            'name' => 'Gaji',
            'type' => 'income',
            'icon' => 'briefcase',
            'color' => '#22C55E',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $updateResponse = $this->patchJson("/api/categories/{$systemCategory->id}", [
            'name' => 'Coba Edit',
        ]);

        $updateResponse->assertForbidden();
        $updateResponse->assertJsonPath('message', 'Kategori sistem tidak dapat diubah.');

        $deleteResponse = $this->deleteJson("/api/categories/{$systemCategory->id}");

        $deleteResponse->assertForbidden();
        $deleteResponse->assertJsonPath('message', 'Kategori sistem tidak dapat dihapus.');
    }
}

<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransactionControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_allows_system_category_for_authenticated_user_transaction(): void
    {
        $user = User::factory()->create();

        $account = Account::create([
            'user_id' => $user->id,
            'name' => 'BCA',
            'type' => 'bank',
            'balance' => 100000,
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        $systemCategory = Category::create([
            'user_id' => null,
            'name' => 'Transportasi',
            'type' => 'expense',
            'icon' => 'car',
            'color' => '#3B82F6',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/transactions', [
            'account_id' => $account->id,
            'category_id' => $systemCategory->id,
            'type' => 'expense',
            'amount' => 25000,
            'description' => 'Naik ojek',
            'transaction_date' => '2026-05-07',
            'tags' => ['transport'],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.category_id', $systemCategory->id);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'account_id' => $account->id,
            'category_id' => $systemCategory->id,
        ]);
    }

    public function test_update_rejects_mismatched_category_type_for_transaction(): void
    {
        $user = User::factory()->create();

        $account = Account::create([
            'user_id' => $user->id,
            'name' => 'BCA',
            'type' => 'bank',
            'balance' => 100000,
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        $expenseCategory = Category::create([
            'user_id' => null,
            'name' => 'Makanan',
            'type' => 'expense',
            'icon' => 'utensils',
            'color' => '#F97316',
            'is_active' => true,
        ]);

        $incomeCategory = Category::create([
            'user_id' => null,
            'name' => 'Gaji',
            'type' => 'income',
            'icon' => 'briefcase',
            'color' => '#22C55E',
            'is_active' => true,
        ]);

        $transaction = Transaction::create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'category_id' => $expenseCategory->id,
            'type' => 'expense',
            'amount' => 15000,
            'description' => 'Makan siang',
            'transaction_date' => '2026-05-07',
        ]);

        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/transactions/{$transaction->id}", [
            'type' => 'income',
            'category_id' => $expenseCategory->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'Kategori tidak sesuai tipe transaksi');

        $response = $this->patchJson("/api/transactions/{$transaction->id}", [
            'category_id' => $incomeCategory->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'Kategori tidak sesuai tipe transaksi');
    }
}

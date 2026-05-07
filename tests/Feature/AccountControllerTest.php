<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconcile_updates_balance_and_creates_adjustment_transaction(): void
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

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/accounts/{$account->id}/reconcile", [
            'actual_balance' => 125000,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('message', 'Rekonsiliasi berhasil.');
        $response->assertJsonPath('data.account.old_balance', 100000);
        $response->assertJsonPath('data.account.new_balance', 125000);
        $response->assertJsonPath('data.account.difference', 25000);
        $response->assertJsonPath('data.adjustment_transaction.type', 'income');

        $this->assertDatabaseHas('accounts', [
            'id' => $account->id,
            'balance' => 125000,
        ]);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'account_id' => $account->id,
            'type' => 'income',
            'amount' => 25000,
            'description' => 'Penyesuaian Saldo Sistem',
        ]);
    }

    public function test_reconcile_returns_message_when_balance_is_already_correct(): void
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

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/accounts/{$account->id}/reconcile", [
            'actual_balance' => 100000,
        ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'Saldo sudah sesuai, tidak ada penyesuaian diperlukan.');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_reconcile_rejects_inactive_account(): void
    {
        $user = User::factory()->create();

        $account = Account::create([
            'user_id' => $user->id,
            'name' => 'BCA',
            'type' => 'bank',
            'balance' => 100000,
            'currency' => 'IDR',
            'is_active' => false,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/accounts/{$account->id}/reconcile", [
            'actual_balance' => 90000,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Akun tidak aktif.');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_reconcile_rejects_other_users_account(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $account = Account::create([
            'user_id' => $otherUser->id,
            'name' => 'BCA',
            'type' => 'bank',
            'balance' => 100000,
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/accounts/{$account->id}/reconcile", [
            'actual_balance' => 90000,
        ]);

        $response->assertForbidden();
        $response->assertJsonPath('message', 'Forbidden.');
    }
}

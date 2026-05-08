<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReconcileTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_adjustment_transaction_with_correct_notes(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $account = Account::create([
            'user_id' => $user->id,
            'name' => 'Bank ABC',
            'type' => 'bank',
            'balance' => 1000000,
            'currency' => 'IDR',
        ]);

        $response = $this->postJson("/api/accounts/{$account->id}/reconcile", [
            'actual_balance' => 1200000,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.account.new_balance', 1200000);
        $response->assertJsonPath('data.account.difference', 200000);

        // Verify transaction notes
        $transaction = Transaction::where('account_id', $account->id)
            ->where('description', 'Penyesuaian Saldo Sistem')
            ->first();

        $this->assertNotNull($transaction);
        $this->assertStringContainsString('Rekonsiliasi: saldo lama 1,000,000.00, saldo baru 1,200,000.00, selisih +200,000.00', $transaction->notes);
    }
}

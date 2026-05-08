<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_triggers_alerts_when_thresholds_are_passed(): void
    {
        $user = User::factory()->create();
        $category = Category::create([
            'user_id' => $user->id,
            'name' => 'Food',
            'type' => 'expense',
        ]);

        $account = \App\Models\Account::create([
            'user_id' => $user->id,
            'name' => 'Cash',
            'type' => 'cash',
            'balance' => 1000000,
        ]);

        $budget = Budget::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'name' => 'Monthly Food',
            'amount' => 100000,
            'spent' => 0,
            'period' => 'monthly',
            'start_date' => now()->startOfMonth(),
            'end_date' => now()->endOfMonth(),
            'is_active' => true,
        ]);

        // 1. Create transaction that passes 50% threshold (set to 60%)
        Transaction::create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 60000,
            'transaction_date' => now()->toDateString(),
        ]);

        $this->assertDatabaseHas('budget_alerts', [
            'budget_id' => $budget->id,
            'threshold' => 50,
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'title' => '💡 Info Anggaran',
        ]);

        // 2. Create another transaction that passes 75% threshold (total 85%)
        Transaction::create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 25000,
            'transaction_date' => now()->toDateString(),
        ]);

        $this->assertDatabaseHas('budget_alerts', [
            'budget_id' => $budget->id,
            'threshold' => 75,
        ]);

        // Ensure 50% alert wasn't duplicated (total alerts should be 2)
        $this->assertEquals(2, \App\Models\BudgetAlert::count());
        $this->assertEquals(2, \App\Models\Notification::count());
    }
}

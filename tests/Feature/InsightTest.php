<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Account;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InsightTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_financial_insights_successfully(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $account = Account::create([
            'user_id' => $user->id,
            'name' => 'Main Account',
            'type' => 'bank',
            'balance' => 10000000,
        ]);

        $category = Category::create([
            'user_id' => $user->id,
            'name' => 'Groceries',
            'type' => 'expense',
        ]);

        // 1. Create baseline data for the last 3 months with some variation
        $amounts = [900000, 1000000, 1100000];
        foreach ($amounts as $i => $amount) {
            $date = Carbon::now()->subMonths($i + 1)->startOfMonth();
            
            // Income
            Transaction::create([
                'user_id' => $user->id,
                'account_id' => $account->id,
                'type' => 'income',
                'amount' => 5000000,
                'transaction_date' => $date->toDateString(),
            ]);

            // Expense
            Transaction::create([
                'user_id' => $user->id,
                'account_id' => $account->id,
                'category_id' => $category->id,
                'type' => 'expense',
                'amount' => $amount,
                'transaction_date' => $date->toDateString(),
            ]);
        }

        // 2. Create anomaly in current month (Groceries spikes to 5M)
        Transaction::create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 5000000,
            'transaction_date' => Carbon::now()->toDateString(),
        ]);

        $response = $this->getJson('/api/insights');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'generated_at',
                'predictions' => [
                    'next_month',
                    'predicted_expense',
                    'predicted_income',
                    'predicted_savings',
                    'expense_trend_pct',
                    'income_trend_pct',
                    'savings_outlook',
                    'top_categories',
                    'data_months_used',
                ],
                'anomalies',
                'recommendations',
            ],
            'meta',
        ]);

        // Check for the anomaly we created
        $response->assertJsonFragment(['category_name' => 'Groceries']);
        $this->assertNotEmpty($response->json('data.anomalies'));
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Budget;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $now = Carbon::now();

        $totalBalance = (float) Account::where('user_id', $userId)
            ->where('is_active', true)
            ->sum('balance');

        $incomeThisMonth = (float) Transaction::forUser($userId)
            ->where('type', 'income')
            ->whereYear('transaction_date', $now->year)
            ->whereMonth('transaction_date', $now->month)
            ->sum('amount');

        $expenseThisMonth = (float) Transaction::forUser($userId)
            ->where('type', 'expense')
            ->whereYear('transaction_date', $now->year)
            ->whereMonth('transaction_date', $now->month)
            ->sum('amount');

        $budgets = Budget::where('user_id', $userId)
            ->where('is_active', true)
            ->with('category')
            ->get()
            ->map(function (Budget $budget) {
                $amount = (float) $budget->amount;
                $spent = (float) $budget->spent;

                return [
                    'id' => $budget->id,
                    'category_id' => $budget->category_id,
                    'name' => $budget->name,
                    'amount' => $budget->amount,
                    'spent' => $budget->spent,
                    'period' => $budget->period,
                    'start_date' => $budget->start_date,
                    'end_date' => $budget->end_date,
                    'is_active' => $budget->is_active,
                    'percentage_used' => $amount > 0 ? round(($spent / $amount) * 100, 2) : 0,
                    'category' => $budget->category,
                ];
            })
            ->values();

        return response()->json([
            'data' => [
                'total_balance' => $totalBalance,
                'income_this_month' => $incomeThisMonth,
                'expense_this_month' => $expenseThisMonth,
                'budgets' => $budgets,
            ],
        ]);
    }
}

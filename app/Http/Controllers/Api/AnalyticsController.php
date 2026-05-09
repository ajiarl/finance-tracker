<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AnalyticsController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $startDate = $request->query('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->query('end_date', Carbon::now()->endOfMonth()->toDateString());
        $accountId = $request->query('account_id');

        // Use explicit table names to avoid ambiguity after joins
        $baseQuery = Transaction::where('transactions.user_id', $userId)
            ->whereBetween('transactions.transaction_date', [$startDate, $endDate]);

        if ($accountId && $accountId !== 'all') {
            $baseQuery->where('transactions.account_id', $accountId);
        }

        // 1. Summary - Use a fresh clone to ensure no accidental joins/modifications
        $summaryData = (clone $baseQuery)
            ->selectRaw("
                SUM(CASE WHEN transactions.type = 'income' THEN transactions.amount ELSE 0 END) as total_income,
                SUM(CASE WHEN transactions.type = 'expense' THEN transactions.amount ELSE 0 END) as total_expense
            ")
            ->first();

        $totalIncome = $summaryData ? (float) $summaryData->total_income : 0;
        $totalExpense = $summaryData ? (float) $summaryData->total_expense : 0;
        $net = $totalIncome - $totalExpense;
        $savingsRate = $totalIncome > 0 ? (($totalIncome - $totalExpense) / $totalIncome) * 100 : 0;

        // 2. Time Series - Group by DATE() to handle datetime columns
        $timeSeries = (clone $baseQuery)
            ->selectRaw("
                DATE(transactions.transaction_date) as period,
                SUM(CASE WHEN transactions.type = 'income' THEN transactions.amount ELSE 0 END) as income,
                SUM(CASE WHEN transactions.type = 'expense' THEN transactions.amount ELSE 0 END) as expense
            ")
            ->groupBy('period')
            ->orderBy('period')
            ->get();

        // 3. Expense by Category - Use leftJoin to see uncategorized if needed (though usually they have categories)
        $expenseByCategory = (clone $baseQuery)
            ->where('transactions.type', 'expense')
            ->leftJoin('categories', 'transactions.category_id', '=', 'categories.id')
            ->selectRaw("
                COALESCE(categories.id, 0) as category_id,
                COALESCE(categories.name, 'Uncategorized') as name,
                COALESCE(categories.color, '#cbd5e1') as color,
                SUM(transactions.amount) as amount
            ")
            ->groupBy('category_id', 'name', 'color')
            ->orderByDesc('amount')
            ->get()
            ->map(function ($item) use ($totalExpense) {
                $item->amount = (float) $item->amount;
                $item->percentage = $totalExpense > 0 ? ($item->amount / $totalExpense) * 100 : 0;
                return $item;
            });

        // 4. Income by Category
        $incomeByCategory = (clone $baseQuery)
            ->where('transactions.type', 'income')
            ->leftJoin('categories', 'transactions.category_id', '=', 'categories.id')
            ->selectRaw("
                COALESCE(categories.id, 0) as category_id,
                COALESCE(categories.name, 'Uncategorized') as name,
                COALESCE(categories.color, '#cbd5e1') as color,
                SUM(transactions.amount) as amount
            ")
            ->groupBy('category_id', 'name', 'color')
            ->orderByDesc('amount')
            ->get()
            ->map(function ($item) use ($totalIncome) {
                $item->amount = (float) $item->amount;
                $item->percentage = $totalIncome > 0 ? ($item->amount / $totalIncome) * 100 : 0;
                return $item;
            });

        return response()->json([
            'data' => [
                'meta' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'generated_at' => now()->toIso8601String(),
                ],
                'summary' => [
                    'total_income' => $totalIncome,
                    'total_expense' => $totalExpense,
                    'net' => $net,
                    'savings_rate' => round($savingsRate, 1),
                ],
                'time_series' => $timeSeries,
                'expense_by_category' => $expenseByCategory,
                'income_by_category' => $incomeByCategory,
            ]
        ]);
    }
}

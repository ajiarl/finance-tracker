<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Budget;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $now = $this->resolveMonth($request);

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

    public function charts(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $month = $this->resolveMonth($request);
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $transactions = Transaction::query()
            ->forUser($userId)
            ->with('category:id,name,type,color')
            ->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('transaction_date')
            ->get(['id', 'type', 'amount', 'transaction_date', 'category_id']);

        $categoryBreakdown = $transactions
            ->where('type', 'expense')
            ->groupBy('category_id')
            ->map(function (Collection $items) {
                $first = $items->first();
                $category = $first?->category;
                $total = (float) $items->sum('amount');

                return [
                    'category_id' => $first?->category_id,
                    'name' => $category?->name ?? 'Tanpa Kategori',
                    'color' => $category?->color,
                    'total' => $total,
                ];
            })
            ->sortByDesc('total')
            ->values();

        $weeklyTrend = $transactions
            ->groupBy(function (Transaction $transaction) {
                return Carbon::parse($transaction->transaction_date)->startOfWeek()->toDateString();
            })
            ->map(function (Collection $items, string $weekStart) {
                $income = (float) $items->where('type', 'income')->sum('amount');
                $expense = (float) $items->where('type', 'expense')->sum('amount');
                $transfer = (float) $items->where('type', 'transfer')->sum('amount');

                return [
                    'week_start' => $weekStart,
                    'week_end' => Carbon::parse($weekStart)->endOfWeek()->toDateString(),
                    'income' => $income,
                    'expense' => $expense,
                    'transfer' => $transfer,
                    'net' => $income - $expense - $transfer,
                ];
            })
            ->values();

        $dailyCashflow = collect();
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $date = $cursor->toDateString();
            $items = $transactions->filter(fn (Transaction $transaction) => $transaction->transaction_date->toDateString() === $date);
            $income = (float) $items->where('type', 'income')->sum('amount');
            $expense = (float) $items->where('type', 'expense')->sum('amount');
            $transfer = (float) $items->where('type', 'transfer')->sum('amount');

            $dailyCashflow->push([
                'date' => $date,
                'income' => $income,
                'expense' => $expense,
                'transfer' => $transfer,
                'net' => $income - $expense - $transfer,
            ]);

            $cursor->addDay();
        }

        return response()->json([
            'data' => [
                'month' => $month->format('Y-m'),
                'category_breakdown' => $categoryBreakdown,
                'weekly_trend' => $weeklyTrend,
                'daily_cashflow' => $dailyCashflow,
            ],
        ]);
    }

    private function resolveMonth(Request $request): Carbon
    {
        $month = $request->query('month');

        if (! $month) {
            return Carbon::now();
        }

        return Carbon::createFromFormat('Y-m', $month)->startOfMonth();
    }
}

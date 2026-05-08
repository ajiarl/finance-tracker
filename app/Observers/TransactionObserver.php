<?php

namespace App\Observers;

use App\Models\Budget;
use App\Models\Transaction;
use App\Services\BudgetAlertService;

class TransactionObserver
{
    public function __construct(
        private BudgetAlertService $alertService
    ) {}

    public function created(Transaction $transaction): void
    {
        $this->adjustMatchingBudgetSpent(
            userId: $transaction->user_id,
            categoryId: $transaction->category_id,
            transactionDate: $transaction->transaction_date,
            delta: (float) $transaction->amount,
            type: $transaction->type,
        );
    }

    public function updated(Transaction $transaction): void
    {
        $this->adjustMatchingBudgetSpent(
            userId: $transaction->getOriginal('user_id'),
            categoryId: $transaction->getOriginal('category_id'),
            transactionDate: $transaction->getOriginal('transaction_date'),
            delta: -1 * (float) $transaction->getOriginal('amount'),
            type: $transaction->getOriginal('type', 'expense'),
        );

        $this->adjustMatchingBudgetSpent(
            userId: $transaction->user_id,
            categoryId: $transaction->category_id,
            transactionDate: $transaction->transaction_date,
            delta: (float) $transaction->amount,
            type: $transaction->type,
        );
    }

    public function deleted(Transaction $transaction): void
    {
        $this->adjustMatchingBudgetSpent(
            userId: $transaction->user_id,
            categoryId: $transaction->category_id,
            transactionDate: $transaction->transaction_date,
            delta: -1 * (float) $transaction->amount,
            type: $transaction->type,
        );
    }

    public function restored(Transaction $transaction): void
    {
        $this->adjustMatchingBudgetSpent(
            userId: $transaction->user_id,
            categoryId: $transaction->category_id,
            transactionDate: $transaction->transaction_date,
            delta: (float) $transaction->amount,
            type: $transaction->type,
        );
    }

    public function forceDeleted(Transaction $transaction): void {}

    private function adjustMatchingBudgetSpent(
        ?int $userId,
        ?int $categoryId,
        $transactionDate,
        float $delta,
        string $type = 'expense'
    ): void {
        if (! $userId || ! $categoryId || ! $transactionDate || $delta === 0.0) {
            return;
        }

        if ($type !== 'expense') {
            return;
        }

        $budget = Budget::query()
            ->where('user_id', $userId)
            ->where('category_id', $categoryId)
            ->where('is_active', true)
            ->whereDate('start_date', '<=', $transactionDate)
            ->whereDate('end_date', '>=', $transactionDate)
            ->first();

        if (! $budget) {
            // Suggest creating a budget for unbudgeted categories (only for new spending)
            if ($delta > 0) {
                $category = \App\Models\Category::find($categoryId);
                if ($category) {
                    \App\Models\Notification::create([
                        'user_id' => $userId,
                        'title' => 'TIP ANGGARAN',
                        'message' => "ANDA MENCATAT PENGELUARAN DI '{$category->name}'. MAU BUAT ANGGARAN UNTUK KATEGORI INI AGAR LEBIH TERKONTROL?",
                        'type' => 'info',
                        'data' => [
                            'category_id' => $categoryId,
                            'category_name' => $category->name,
                            'suggestion' => 'create_budget'
                        ]
                    ]);
                }
            }
            return;
        }

        $budget->spent = max(0, (float) $budget->spent + $delta);
        $budget->save();

        // Picu pengecekan alert setelah spent ter-update
        $this->alertService->checkAndNotify($budget);
    }
}

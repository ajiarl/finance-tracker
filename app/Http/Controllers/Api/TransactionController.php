<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $transactions = Transaction::query()
            ->forUser($request->user()->id)
            ->with(['account', 'category'])
            ->when($request->filled('account_id'), function (Builder $query) use ($request) {
                $query->where('account_id', $request->account_id);
            })
            ->when($request->filled('category_id'), function (Builder $query) use ($request) {
                $query->where('category_id', $request->category_id);
            })
            ->when($request->filled('type'), function (Builder $query) use ($request) {
                $query->where('type', $request->type);
            })
            ->when($request->filled('date_from'), function (Builder $query) use ($request) {
                $query->whereDate('transaction_date', '>=', $request->date_from);
            })
            ->when($request->filled('date_to'), function (Builder $query) use ($request) {
                $query->whereDate('transaction_date', '<=', $request->date_to);
            })
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $transactions]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_id' => 'required|integer|exists:accounts,id',
            'category_id' => 'nullable|integer|exists:categories,id',
            'type' => 'required|in:income,expense,transfer',
            'amount' => 'required|numeric|gt:0',
            'description' => 'nullable|string|max:255',
            'transaction_date' => 'required|date',
            'reference_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
        ]);

        $account = $this->getOwnedAccount($request->user()->id, (int) $validated['account_id']);
        if (! $account) {
            return response()->json(['error' => 'Account not found.'], 404);
        }

        $category = $this->resolveAccessibleCategory($request->user()->id, $validated['category_id'] ?? null);
        if (($validated['category_id'] ?? null) && ! $category) {
            return response()->json(['error' => 'Category not found.'], 404);
        }

        if ($category && $category->type !== $validated['type']) {
            return response()->json(['error' => 'Kategori tidak sesuai tipe transaksi'], 422);
        }

        $transaction = DB::transaction(function () use ($request, $validated, $account) {
            $transaction = Transaction::create([
                'user_id' => $request->user()->id,
                'account_id' => $account->id,
                'category_id' => $validated['category_id'] ?? null,
                'type' => $validated['type'],
                'amount' => $validated['amount'],
                'description' => $validated['description'] ?? null,
                'transaction_date' => $validated['transaction_date'],
                'reference_number' => $validated['reference_number'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'tags' => $validated['tags'] ?? null,
            ]);

            $this->applyBalanceDelta($account, $validated['type'], (float) $validated['amount']);

            return $transaction->load(['account', 'category']);
        });

        return response()->json(['data' => $transaction], 201);
    }

    public function show(Request $request, Transaction $transaction): JsonResponse
    {
        if ($transaction->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return response()->json(['data' => $transaction->load(['account', 'category'])]);
    }

    public function update(Request $request, Transaction $transaction): JsonResponse
    {
        if ($transaction->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'account_id' => 'sometimes|integer|exists:accounts,id',
            'category_id' => 'nullable|integer|exists:categories,id',
            'type' => 'sometimes|in:income,expense,transfer',
            'amount' => 'sometimes|numeric|gt:0',
            'description' => 'nullable|string|max:255',
            'transaction_date' => 'sometimes|date',
            'reference_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
        ]);

        $newAccount = isset($validated['account_id'])
            ? $this->getOwnedAccount($request->user()->id, (int) $validated['account_id'])
            : $transaction->account;

        if (! $newAccount) {
            return response()->json(['error' => 'Account not found.'], 404);
        }

        $newCategoryId = array_key_exists('category_id', $validated)
            ? $validated['category_id']
            : $transaction->category_id;

        $newCategory = $this->resolveAccessibleCategory($request->user()->id, $newCategoryId);
        if ($newCategoryId && ! $newCategory) {
            return response()->json(['error' => 'Category not found.'], 404);
        }

        $newType = $validated['type'] ?? $transaction->type;
        if ($newCategory && $newCategory->type !== $newType) {
            return response()->json(['error' => 'Kategori tidak sesuai tipe transaksi'], 422);
        }

        DB::transaction(function () use ($transaction, $validated, $newAccount, $newCategoryId) {
            $oldAccount = $transaction->account()->firstOrFail();
            $this->applyBalanceDelta($oldAccount, $transaction->type, -1 * (float) $transaction->amount);

            $transaction->update([
                'account_id' => $newAccount->id,
                'category_id' => $newCategoryId,
                'type' => $validated['type'] ?? $transaction->type,
                'amount' => $validated['amount'] ?? $transaction->amount,
                'description' => array_key_exists('description', $validated) ? $validated['description'] : $transaction->description,
                'transaction_date' => $validated['transaction_date'] ?? $transaction->transaction_date,
                'reference_number' => array_key_exists('reference_number', $validated) ? $validated['reference_number'] : $transaction->reference_number,
                'notes' => array_key_exists('notes', $validated) ? $validated['notes'] : $transaction->notes,
                'tags' => array_key_exists('tags', $validated) ? $validated['tags'] : $transaction->tags,
            ]);

            $transaction->refresh();
            $this->applyBalanceDelta($newAccount, $transaction->type, (float) $transaction->amount);
        });

        return response()->json(['data' => $transaction->fresh(['account', 'category'])]);
    }

    public function destroy(Request $request, Transaction $transaction): JsonResponse
    {
        if ($transaction->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        DB::transaction(function () use ($transaction) {
            $account = $transaction->account()->firstOrFail();
            $this->applyBalanceDelta($account, $transaction->type, -1 * (float) $transaction->amount);
            $transaction->delete();
        });

        return response()->json(['message' => 'Transaksi berhasil dihapus.']);
    }

    private function getOwnedAccount(int $userId, int $accountId): ?Account
    {
        return Account::where('user_id', $userId)->find($accountId);
    }

    private function resolveAccessibleCategory(int $userId, ?int $categoryId): ?Category
    {
        if (! $categoryId) {
            return null;
        }

        return Category::query()
            ->where('id', $categoryId)
            ->where(function ($query) use ($userId) {
                $query->where('user_id', $userId)
                    ->orWhereNull('user_id');
            })
            ->first();
    }

    private function applyBalanceDelta(Account $account, string $type, float $amount): void
    {
        $delta = match ($type) {
            'income' => $amount,
            'expense', 'transfer' => -1 * $amount,
        };

        $account->balance = (float) $account->balance + $delta;
        $account->save();
    }
}

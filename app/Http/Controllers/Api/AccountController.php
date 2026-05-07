<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountController extends Controller
{
    public function index(Request $request)
    {
        $accounts = Account::where('user_id', $request->user()->id)->get();
        return response()->json(['data' => $accounts]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'    => 'required|string|max:255',
            'type'    => 'required|in:cash,bank,e-wallet,credit,investment',
            'balance' => 'nullable|numeric',
        ]);

        $account = Account::create([
            'user_id'  => $request->user()->id,
            'name'     => $request->name,
            'type'     => $request->type,
            'balance'  => $request->balance ?? 0,
            'currency' => 'IDR',
        ]);

        return response()->json(['data' => $account], 201);
    }

    public function show(Request $request, Account $account)
    {
        if ($account->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        return response()->json(['data' => $account]);
    }

    public function update(Request $request, Account $account)
    {
        if ($account->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $request->validate([
            'name'    => 'sometimes|string|max:255',
            'type'    => 'sometimes|in:cash,bank,e-wallet,credit,investment',
            'balance' => 'sometimes|numeric',
        ]);

        $account->update($request->only(['name', 'type', 'balance', 'is_active']));
        return response()->json(['data' => $account]);
    }

    public function destroy(Request $request, Account $account)
    {
        if ($account->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        $account->delete();
        return response()->json(['message' => 'Akun berhasil dihapus.']);
    }

    public function reconcile(Request $request, Account $account): JsonResponse
    {
        if ($account->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (! $account->is_active) {
            return response()->json(['message' => 'Akun tidak aktif.'], 422);
        }

        $validated = $request->validate([
            'actual_balance' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
        ]);

        $actualBalance = (float) $validated['actual_balance'];
        $currentBalance = (float) $account->balance;
        $difference = round($actualBalance - $currentBalance, 2);

        if ($difference === 0.0) {
            return response()->json([
                'message' => 'Saldo sudah sesuai, tidak ada penyesuaian diperlukan.',
                'data' => ['balance' => $account->balance],
            ]);
        }

        $transaction = DB::transaction(function () use ($account, $actualBalance, $currentBalance, $difference, $request) {
            $account->update(['balance' => $actualBalance]);

            return Transaction::create([
                'user_id' => $request->user()->id,
                'account_id' => $account->id,
                'category_id' => null,
                'type' => $difference > 0 ? 'income' : 'expense',
                'amount' => abs($difference),
                'description' => 'Penyesuaian Saldo Sistem',
                'transaction_date' => now()->toDateString(),
                'notes' => sprintf(
                    'Rekonsiliasi: saldo lama %s, saldo baru %s, selisih %s',
                    number_format($currentBalance, 2),
                    number_format($actualBalance, 2),
                    ($difference > 0 ? '+' : '') . number_format($difference, 2)
                ),
            ]);
        });

        return response()->json([
            'message' => 'Rekonsiliasi berhasil.',
            'data' => [
                'account' => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'old_balance' => $currentBalance,
                    'new_balance' => $actualBalance,
                    'difference' => $difference,
                ],
                'adjustment_transaction' => [
                    'id' => $transaction->id,
                    'type' => $transaction->type,
                    'amount' => $transaction->amount,
                ],
            ],
        ], 201);
    }
}

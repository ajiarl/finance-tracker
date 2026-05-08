<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class AccountManagementController extends Controller
{
    /**
     * GET /api/user/export-data
     * Kumpulkan semua data finansial user dan return sebagai file JSON.
     */
    public function exportData(Request $request): Response
    {
        $user = $request->user()->load([
            'accounts.transactions',
            'categories',
        ]);

        $budgets = DB::table('budgets')
            ->where('user_id', $user->id)
            ->get();

        $payload = [
            'export_info' => [
                'exported_at' => now()->toISOString(),
                'exported_by' => $user->email,
                'app' => 'Finance Tracker',
                'format_version' => '1.0',
            ],
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale,
                'currency' => $user->currency,
                'created_at' => $user->created_at->toISOString(),
            ],
            'accounts' => $user->accounts->map(fn ($account) => [
                'name' => $account->name,
                'type' => $account->type,
                'balance' => $account->balance,
                'currency' => $account->currency,
                'is_active' => $account->is_active,
                'created_at' => $account->created_at->toISOString(),
                'transactions' => $account->transactions->map(fn ($transaction) => [
                    'type' => $transaction->type,
                    'amount' => $transaction->amount,
                    'description' => $transaction->description,
                    'transaction_date' => $transaction->transaction_date,
                    'reference_number' => $transaction->reference_number,
                    'notes' => $transaction->notes,
                    'tags' => $transaction->tags,
                    'created_at' => $transaction->created_at->toISOString(),
                ]),
            ]),
            'categories' => $user->categories->map(fn ($category) => [
                'name' => $category->name,
                'type' => $category->type,
                'icon' => $category->icon,
                'color' => $category->color,
                'is_active' => $category->is_active,
            ]),
            'budgets' => $budgets,
        ];

        $filename = 'export-finance-tracker-' . now()->format('Y-m-d') . '.json';
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return response($json, 200)
            ->header('Content-Type', 'application/json')
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"")
            ->header('Content-Length', strlen($json));
    }

    /**
     * DELETE /api/user/delete-account
     * Hapus semua data user secara berurutan, lalu hapus user-nya.
     */
    public function deleteAccount(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (! Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Password tidak sesuai.',
            ], 403);
        }

        DB::transaction(function () use ($user) {
            $accountIds = DB::table('accounts')
                ->where('user_id', $user->id)
                ->pluck('id');

            DB::table('transactions')
                ->whereIn('account_id', $accountIds)
                ->delete();

            DB::table('accounts')
                ->where('user_id', $user->id)
                ->delete();

            DB::table('categories')
                ->where('user_id', $user->id)
                ->delete();

            DB::table('budgets')
                ->where('user_id', $user->id)
                ->delete();

            DB::table('notifications')
                ->where('user_id', $user->id)
                ->delete();

            DB::table('personal_access_tokens')
                ->where('tokenable_type', get_class($user))
                ->where('tokenable_id', $user->id)
                ->delete();

            DB::table('users')
                ->where('id', $user->id)
                ->delete();
        });

        return response()->json([
            'message' => 'Akun dan seluruh data berhasil dihapus secara permanen.',
        ]);
    }
}

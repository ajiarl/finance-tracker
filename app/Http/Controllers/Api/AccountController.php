<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\Http\Request;

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
}
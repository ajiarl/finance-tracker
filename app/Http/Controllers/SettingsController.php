<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'locale' => $user->locale,
                'currency' => $user->currency,
                'supported_locales' => User::SUPPORTED_LOCALES,
                'supported_currencies' => User::SUPPORTED_CURRENCIES,
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'locale' => [
                'sometimes',
                'string',
                'in:' . implode(',', User::SUPPORTED_LOCALES),
            ],
            'currency' => [
                'sometimes',
                'string',
                'in:' . implode(',', User::SUPPORTED_CURRENCIES),
            ],
        ]);

        if (empty($validated)) {
            return response()->json([
                'message' => 'Tidak ada field yang valid untuk diupdate.',
                'allowed_fields' => ['locale', 'currency'],
            ], 422);
        }

        $request->user()->update($validated);
        $user = $request->user()->fresh();

        return response()->json([
            'message' => 'Pengaturan berhasil disimpan.',
            'data' => [
                'locale' => $user->locale,
                'currency' => $user->currency,
            ],
        ]);
    }
}

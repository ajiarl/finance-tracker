<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $categories = Category::query()
            ->where(function ($query) use ($request) {
                $query->where('user_id', $request->user()->id)
                    ->orWhereNull('user_id');
            })
            ->where('is_active', true)
            ->when($request->filled('type'), function ($query) use ($request) {
                $query->where('type', $request->type);
            })
            ->orderByRaw('user_id IS NOT NULL')
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $categories]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'  => 'required|string|max:100',
            'type'  => 'required|in:income,expense',
            'icon'  => 'nullable|string|max:50',
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $category = Category::create([
            ...$validated,
            'user_id' => $request->user()->id,
            'is_active' => true,
        ]);

        return response()->json(['data' => $category], 201);
    }

    public function show(Request $request, Category $category): JsonResponse
    {
        if ($category->user_id !== $request->user()->id && ! is_null($category->user_id)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return response()->json(['data' => $category]);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        if (is_null($category->user_id)) {
            return response()->json(['message' => 'Kategori sistem tidak dapat diubah.'], 403);
        }

        if ($category->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'name'      => 'sometimes|string|max:100',
            'type'      => 'sometimes|in:income,expense',
            'icon'      => 'nullable|string|max:50',
            'color'     => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'is_active' => 'sometimes|boolean',
        ]);

        $category->update($validated);

        return response()->json(['data' => $category]);
    }

    public function destroy(Request $request, Category $category): JsonResponse
    {
        if (is_null($category->user_id)) {
            return response()->json(['message' => 'Kategori sistem tidak dapat dihapus.'], 403);
        }

        if ($category->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $category->delete();

        return response()->json(null, 204);
    }
}

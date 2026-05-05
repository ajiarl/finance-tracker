<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Models\Category;
use Illuminate\Http\Request;

class BudgetController extends Controller
{
    public function index(Request $request)
    {
        $budgets = Budget::where('user_id', $request->user()->id)
            ->with('category')
            ->get();

        return response()->json(['data' => $budgets]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'nullable|integer|exists:categories,id',
            'name' => 'required|string|max:255',
            'amount' => 'required|numeric|gt:0',
            'period' => 'required|in:monthly,weekly,yearly',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'is_active' => 'sometimes|boolean',
        ]);

        $categoryId = $this->resolveOwnedCategoryId($request->user()->id, $validated['category_id'] ?? null);
        if (($validated['category_id'] ?? null) && ! $categoryId) {
            return response()->json(['error' => 'Category not found.'], 404);
        }

        $budget = Budget::create([
            'user_id' => $request->user()->id,
            'category_id' => $categoryId,
            'name' => $validated['name'],
            'amount' => $validated['amount'],
            'spent' => 0,
            'period' => $validated['period'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json(['data' => $budget->fresh()->load('category')], 201);
    }

    public function show(Request $request, Budget $budget)
    {
        if ($budget->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return response()->json(['data' => $budget->load('category')]);
    }

    public function update(Request $request, Budget $budget)
    {
        if ($budget->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'category_id' => 'nullable|integer|exists:categories,id',
            'name' => 'sometimes|string|max:255',
            'amount' => 'sometimes|numeric|gt:0',
            'period' => 'sometimes|in:monthly,weekly,yearly',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after:start_date',
            'is_active' => 'sometimes|boolean',
        ]);

        $newCategoryId = array_key_exists('category_id', $validated)
            ? $this->resolveOwnedCategoryId($request->user()->id, $validated['category_id'])
            : $budget->category_id;

        if (array_key_exists('category_id', $validated) && $validated['category_id'] && ! $newCategoryId) {
            return response()->json(['error' => 'Category not found.'], 404);
        }

        $startDate = $validated['start_date'] ?? $budget->start_date;
        $endDate = $validated['end_date'] ?? $budget->end_date;

        if ($startDate && $endDate && $endDate <= $startDate) {
            return response()->json([
                'message' => 'The end date field must be a date after start date.',
                'errors' => ['end_date' => ['The end date field must be a date after start date.']],
            ], 422);
        }

        $budget->update([
            'category_id' => $newCategoryId,
            'name' => $validated['name'] ?? $budget->name,
            'amount' => $validated['amount'] ?? $budget->amount,
            'period' => $validated['period'] ?? $budget->period,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'is_active' => array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : $budget->is_active,
        ]);

        return response()->json(['data' => $budget->fresh('category')]);
    }

    public function destroy(Request $request, Budget $budget)
    {
        if ($budget->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $budget->delete();

        return response()->json(['message' => 'Budget berhasil dihapus.']);
    }

    private function resolveOwnedCategoryId(int $userId, ?int $categoryId): ?int
    {
        if (! $categoryId) {
            return null;
        }

        $category = Category::where('user_id', $userId)->find($categoryId);

        return $category?->id;
    }
}

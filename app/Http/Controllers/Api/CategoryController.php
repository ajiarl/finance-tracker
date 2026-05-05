<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = Category::where('user_id', $request->user()->id);

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'  => 'required|string|max:100',
            'type'  => 'required|in:income,expense',
            'icon'  => 'nullable|string|max:50',
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $category = Category::create([
            'user_id' => $request->user()->id,
            'name'    => $request->name,
            'type'    => $request->type,
            'icon'    => $request->icon,
            'color'   => $request->color,
        ]);

        return response()->json(['data' => $category], 201);
    }

    public function show(Request $request, Category $category)
    {
        if ($category->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return response()->json(['data' => $category]);
    }

    public function update(Request $request, Category $category)
    {
        if ($category->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $request->validate([
            'name'      => 'sometimes|string|max:100',
            'type'      => 'sometimes|in:income,expense',
            'icon'      => 'nullable|string|max:50',
            'color'     => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'is_active' => 'sometimes|boolean',
        ]);

        $category->update($request->only(['name', 'type', 'icon', 'color', 'is_active']));

        return response()->json(['data' => $category]);
    }

    public function destroy(Request $request, Category $category)
    {
        if ($category->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $category->delete();

        return response()->json(['message' => 'Kategori berhasil dihapus.']);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NavigationItems;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class NavigationItemsController extends Controller
{
    
    public function index(Request $request): JsonResponse
    {
        
        $user = $request->user();

        if (! $user) {
            return response()->json(['ok' => false, 'message' => 'Unauthenticated'], 401);
        }

        $allowedIds = $user->hasRole('super_admin')
            ? null
            : DB::table('role_navigations')
                ->whereIn('role_id', $user->roles->pluck('id'))
                ->pluck('navigation_item_id')
                ->all();

        $can = fn ($item) => $item->is_active
            && ($allowedIds === null || in_array($item->id, $allowedIds));

        $items = NavigationItems::with('children')
            ->root()
            ->active()
            ->orderBy('sort_order')
            ->get()
            ->map(function ($item) use ($can) {
                $item->setRelation('children', $item->children->filter($can)->values());
                return $item;
            })
            ->filter(fn ($item) => $item->is_dropdown ? $item->children->isNotEmpty() : $can($item))
            ->values();

        return response()->json(['ok' => true, 'data' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:navigation_items,id'],
            'key' => ['required', 'string', 'max:100', 'unique:navigation_items,key'],
            'label' => ['required', 'string', 'max:150'],
            'path' => ['nullable', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:100'],
            'is_dropdown' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);

        $item = NavigationItems::create($validated);

        return response()->json([
            'ok' => true,
            'message' => 'Navigation item created successfully.',
            'data' => $item,
        ], 201);
    }

    public function show(NavigationItems $navigationItem): JsonResponse
    {
        $navigationItem->load('children');

        return response()->json(['ok' => true, 'data' => $navigationItem]);
    }

    public function update(Request $request, NavigationItems $navigationItem): JsonResponse
    {
        $validated = $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:navigation_items,id'],
            'key' => [
                'required', 'string', 'max:100',
                Rule::unique('navigation_items', 'key')->ignore($navigationItem->id),
            ],
            'label' => ['required', 'string', 'max:150'],
            'path' => ['nullable', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:100'],
            'is_dropdown' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);

        if (isset($validated['parent_id']) && $validated['parent_id'] == $navigationItem->id) {
            return response()->json([
                'ok' => false,
                'message' => 'A navigation item cannot be its own parent.',
            ], 422);
        }

        $navigationItem->update($validated);

        return response()->json([
            'ok' => true,
            'message' => 'Navigation item updated successfully.',
            'data' => $navigationItem->fresh(),
        ]);
    }

    public function destroy(NavigationItems $navigationItem): JsonResponse
    {
        if ($navigationItem->children()->exists()) {
            return response()->json([
                'ok' => false,
                'message' => 'Cannot delete a navigation item that has children.',
            ], 422);
        }

        $navigationItem->delete();

        return response()->json([
            'ok' => true,
            'message' => 'Navigation item deleted successfully.',
        ]);
    }
}
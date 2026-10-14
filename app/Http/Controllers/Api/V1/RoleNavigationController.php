<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NavigationItems;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class RoleNavigationController extends Controller
{
    public function show(Role $role): JsonResponse
    {
        $assigned = DB::table('role_navigations')
            ->where('role_id', $role->id)
            ->pluck('navigation_item_id')
            ->all();

        $tree = NavigationItems::with('children')
            ->root()
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($parent) => [
                'id' => $parent->id,
                'key' => $parent->key,
                'label' => $parent->label,
                'is_dropdown' => $parent->is_dropdown,
                'visible' => in_array($parent->id, $assigned),
                'children' => $parent->children->map(fn ($child) => [
                    'id' => $child->id,
                    'key' => $child->key,
                    'label' => $child->label,
                    'visible' => in_array($child->id, $assigned),
                ])->values(),
            ])
            ->values();

        return response()->json(['ok' => true, 'data' => $tree]);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $data = $request->validate([
            'navigation_item_ids' => ['present', 'array'],
            'navigation_item_ids.*' => ['integer', 'exists:navigation_items,id'],
        ]);

        $ids = collect($data['navigation_item_ids'])->unique()->values();

        DB::transaction(function () use ($role, $ids) {
            DB::table('role_navigations')->where('role_id', $role->id)->delete();

            if ($ids->isNotEmpty()) {
                DB::table('role_navigations')->insert(
                    $ids->map(fn ($id) => [
                        'role_id' => $role->id,
                        'navigation_item_id' => $id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])->all()
                );
            }
        });

        return response()->json(['ok' => true, 'message' => 'Menu access updated.']);
    }
}
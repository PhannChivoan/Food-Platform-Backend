<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NavigationItems;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Database\Seeders\ModulePermissionSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleNavigationController extends Controller
{
    public function show(Role $role): JsonResponse
    {
        $assigned = DB::table('role_navigations')
            ->where('role_id', $role->id)
            ->pluck('navigation_item_id')
            ->all();

        // The access screen has two levels. A group inside a group (Landing Page > Home > ...) is not a row of its
        // own: its pages are listed under the top group, named "Home · Hero highlights".
        $leaves = function ($item, $prefix = '') use (&$leaves) {
            $out = [];
            foreach ($item->children as $child) {
                if ($child->is_dropdown && $child->children->isNotEmpty()) {
                    $out = array_merge($out, $leaves($child, $child->label . ' · '));
                } else {
                    $out[] = $child->setAttribute('shown_label', $prefix . $child->label);
                }
            }
            return $out;
        };

        $tree = NavigationItems::with('children.children.children')
            ->root()
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($parent) => [
                'id' => $parent->id,
                'key' => $parent->key,
                'label' => $parent->label,
                'is_dropdown' => $parent->is_dropdown,
                'visible' => in_array($parent->id, $assigned),
                'children' => collect($leaves($parent))->map(fn ($child) => [
                    'id' => $child->id,
                    'key' => $child->key,
                    'label' => $child->shown_label,
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

        // the screen only knows pages and top groups: a page that is on also switches on the groups it sits in
        $parents = DB::table('navigation_items')->pluck('parent_id', 'id');
        foreach ($ids->all() as $id) {
            for ($up = $parents[$id] ?? null; $up; $up = $parents[$up] ?? null) {
                $ids->push($up);
            }
        }
        $ids = $ids->unique()->values();

        $before = DB::table('role_navigations')->where('role_id', $role->id)->pluck('navigation_item_id')->all();

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

        $keyOf = fn ($list) => NavigationItems::whereIn('id', $list)->pluck('key')->all();
        $shown = array_values(array_diff($ids->all(), $before));
        $hidden = array_values(array_diff($before, $ids->all()));
        if ($shown || $hidden) {
            \App\Models\ActivityLog::record('modules_changed', $role, ['role' => $role->name, 'shown' => $keyOf($shown), 'hidden' => $keyOf($hidden)]);
        }

        // a page switched on for the first time starts with full view/create/update/delete,
        // which Permission Policies can then narrow down
        $keys = NavigationItems::whereIn('id', $ids)->whereNotNull('path')->pluck('key');
        foreach ($keys as $key) {
            $existing = Permission::whereIn('name', array_map(fn ($a) => "$key.$a", ModulePermissionSeeder::ACTIONS))->get();
            if ($existing->isNotEmpty() && $role->hasAnyPermission($existing)) {
                continue;
            }
            foreach (ModulePermissionSeeder::ACTIONS as $action) {
                $role->givePermissionTo(Permission::firstOrCreate(['name' => "$key.$action", 'guard_name' => 'web']));
            }
        }

        return response()->json(['ok' => true, 'message' => 'Menu access updated.']);
    }
}
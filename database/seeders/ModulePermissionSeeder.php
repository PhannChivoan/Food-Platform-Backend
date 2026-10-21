<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Four permissions (view / create / update / delete) for every page of the admin menu,
 * named "<navigation key>.<action>" (e.g. "orders.view", "promotions.delete").
 * A role that can already open a page gets all four, so nothing changes until an admin
 * unticks something in Permission Policies. Safe to run again:
 *   php artisan db:seed --class=ModulePermissionSeeder
 */
class ModulePermissionSeeder extends Seeder
{
    public const ACTIONS = ['view', 'create', 'update', 'delete'];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $items = DB::table('navigation_items')->whereNotNull('path')->get(['id', 'key']);

        foreach ($items as $item) {
            foreach (self::ACTIONS as $action) {
                Permission::firstOrCreate(['name' => "{$item->key}.{$action}", 'guard_name' => 'web']);
            }
        }

        foreach (Role::all() as $role) {
            $keys = DB::table('role_navigations')
                ->join('navigation_items', 'navigation_items.id', '=', 'role_navigations.navigation_item_id')
                ->where('role_navigations.role_id', $role->id)
                ->whereNotNull('navigation_items.path')
                ->pluck('navigation_items.key');

            $names = [];
            foreach ($keys as $key) {
                foreach (self::ACTIONS as $action) {
                    $names[] = "{$key}.{$action}";
                }
            }
            if ($role->name === 'super_admin') {
                $names = Permission::where('name', 'like', '%.%')->pluck('name')->all();
            }

            // only add: never remove what an admin already configured
            $role->givePermissionTo($names);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

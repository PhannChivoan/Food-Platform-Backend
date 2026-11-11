<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

// "My Deliveries" becomes a dropdown with a page per section: To deliver (the existing page and key, so roles and
// permissions carry over), Available orders and Completed.
return new class extends Migration {
    private const ACTIONS = ['view', 'create', 'update', 'delete'];

    public function up()
    {
        $now = now();

        DB::table('navigation_items')->updateOrInsert(
            ['key' => 'my-deliveries-group'],
            ['parent_id' => null, 'label' => 'My Deliveries', 'path' => null, 'icon' => 'Bike',
             'is_dropdown' => true, 'sort_order' => 2, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]
        );
        $group = DB::table('navigation_items')->where('key', 'my-deliveries-group')->value('id');

        DB::table('navigation_items')->where('key', 'my-deliveries')->update([
            'parent_id' => $group, 'label' => 'To deliver', 'path' => '/driver', 'icon' => 'Route', 'sort_order' => 1, 'updated_at' => $now,
        ]);
        foreach ([['my-deliveries-available', 'Available orders', '/driver/available', 'Radar', 2], ['my-deliveries-completed', 'Completed', '/driver/completed', 'CheckCircle2', 3]] as [$key, $label, $path, $icon, $sort]) {
            DB::table('navigation_items')->updateOrInsert(
                ['key' => $key],
                ['parent_id' => $group, 'label' => $label, 'path' => $path, 'icon' => $icon,
                 'is_dropdown' => false, 'sort_order' => $sort, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $ids = DB::table('navigation_items')->whereIn('key', ['my-deliveries-group', 'my-deliveries', 'my-deliveries-available', 'my-deliveries-completed'])->pluck('id', 'key');
        $mineId = $ids['my-deliveries'];

        // whoever could open My Deliveries gets the whole dropdown, with the same view / create / update / delete rights
        $roleIds = DB::table('role_navigations')->where('navigation_item_id', $mineId)->pluck('role_id');
        foreach (Role::whereIn('id', $roleIds)->get() as $role) {
            foreach ($ids as $itemId) {
                DB::table('role_navigations')->updateOrInsert(['role_id' => $role->id, 'navigation_item_id' => $itemId], ['created_at' => $now, 'updated_at' => $now]);
            }
        }
        foreach (['my-deliveries-available', 'my-deliveries-completed'] as $key) {
            foreach (self::ACTIONS as $action) {
                $perm = Permission::firstOrCreate(['name' => "$key.$action", 'guard_name' => 'web']);
                foreach (Role::permission("my-deliveries.$action")->get() as $role) {
                    $role->givePermissionTo($perm);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down()
    {
        DB::table('navigation_items')->where('key', 'my-deliveries')->update(['parent_id' => null, 'label' => 'My Deliveries', 'icon' => 'Bike', 'sort_order' => 2]);

        $ids = DB::table('navigation_items')->whereIn('key', ['my-deliveries-group', 'my-deliveries-available', 'my-deliveries-completed'])->pluck('id');
        DB::table('role_navigations')->whereIn('navigation_item_id', $ids)->delete();
        DB::table('navigation_items')->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::whereIn('name', array_merge(...array_map(fn ($k) => array_map(fn ($a) => "$k.$a", self::ACTIONS), ['my-deliveries-available', 'my-deliveries-completed'])))->get()->each->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

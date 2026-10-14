<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 1) platform commission setting (no subscription: the platform keeps a % of every order)
// 2) a "My Deliveries" module so drivers work inside the admin dashboard; the super admin
//    decides which roles see it from Roles & Permissions (role_navigations).
return new class extends Migration {
    public function up()
    {
        $now = now();

        DB::table('platform_settings')->updateOrInsert(
            ['key' => 'commission_rate'],
            ['value' => '12', 'type' => 'integer', 'group' => 'payments', 'created_at' => $now, 'updated_at' => $now]
        );

        DB::table('navigation_items')->updateOrInsert(
            ['key' => 'my-deliveries'],
            [
                'parent_id' => null, 'label' => 'My Deliveries', 'path' => '/driver', 'icon' => 'Bike',
                'is_dropdown' => false, 'sort_order' => 2, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]
        );
        $navId = DB::table('navigation_items')->where('key', 'my-deliveries')->value('id');

        foreach (['rider', 'admin'] as $roleName) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');
            if ($roleId) {
                DB::table('role_navigations')->updateOrInsert(
                    ['role_id' => $roleId, 'navigation_item_id' => $navId],
                    ['created_at' => $now, 'updated_at' => $now]
                );
            }
        }
    }

    public function down()
    {
        $navId = DB::table('navigation_items')->where('key', 'my-deliveries')->value('id');
        if ($navId) {
            DB::table('role_navigations')->where('navigation_item_id', $navId)->delete();
            DB::table('navigation_items')->where('id', $navId)->delete();
        }
        DB::table('platform_settings')->where('key', 'commission_rate')->delete();
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Models\Role;

// Landing Page menu: one level deeper. Landing Page > Home / Services / About > the card groups of that page.
return new class extends Migration {
    // sub group key => [label, sort, [module keys]]
    private const GROUPS = [
        'landing-home' => ['Home', 1, ['home-perks', 'home-stats', 'home-categories', 'home-services', 'home-story', 'home-help']],
        'landing-services' => ['Services', 2, ['services-tools', 'services-points', 'services-features']],
        'landing-about' => ['About us', 3, ['about-numbers', 'about-pillars', 'about-timeline', 'about-impact']],
    ];

    private const LABELS = [
        'home-perks' => 'Hero highlights', 'home-stats' => 'Platform numbers', 'home-categories' => 'Food categories',
        'home-services' => 'What we bring', 'home-story' => 'Story highlights', 'home-help' => 'Help & partner cards',
        'services-tools' => 'Tool cards', 'services-points' => 'Highlight chips', 'services-features' => 'Feature cards',
        'about-numbers' => 'Numbers', 'about-pillars' => 'Pillars', 'about-timeline' => 'Timeline', 'about-impact' => 'Impact numbers',
    ];

    public function up()
    {
        $now = now();
        $root = DB::table('navigation_items')->where('key', 'landing-page')->value('id');
        $groupIds = [];

        foreach (self::GROUPS as $key => [$label, $sort, $modules]) {
            DB::table('navigation_items')->updateOrInsert(
                ['key' => $key],
                ['parent_id' => $root, 'label' => $label, 'path' => null, 'icon' => 'LayoutGrid',
                 'is_dropdown' => true, 'sort_order' => $sort, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]
            );
            $groupId = DB::table('navigation_items')->where('key', $key)->value('id');
            $groupIds[] = $groupId;

            foreach ($modules as $i => $module) {
                DB::table('navigation_items')->where('key', "landing-$module")->update([
                    'parent_id' => $groupId, 'label' => self::LABELS[$module], 'sort_order' => $i + 1, 'updated_at' => $now,
                ]);
            }
        }

        // whoever can open a card group sees the group it sits in
        foreach (['admin', 'super_admin'] as $roleName) {
            $role = Role::where('name', $roleName)->first();
            if (! $role) {
                continue;
            }
            foreach ($groupIds as $id) {
                DB::table('role_navigations')->updateOrInsert(['role_id' => $role->id, 'navigation_item_id' => $id], ['created_at' => $now, 'updated_at' => $now]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down()
    {
        $root = DB::table('navigation_items')->where('key', 'landing-page')->value('id');
        $sort = 1;
        foreach (self::GROUPS as [$label, , $modules]) {
            foreach ($modules as $module) {
                DB::table('navigation_items')->where('key', "landing-$module")->update([
                    'parent_id' => $root, 'label' => "$label · " . self::LABELS[$module], 'sort_order' => $sort++,
                ]);
            }
        }

        $ids = DB::table('navigation_items')->whereIn('key', array_keys(self::GROUPS))->pluck('id');
        DB::table('role_navigations')->whereIn('navigation_item_id', $ids)->delete();
        DB::table('navigation_items')->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

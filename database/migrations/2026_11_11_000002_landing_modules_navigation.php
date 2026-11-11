<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

// Sidebar: a "Landing Page" group with one page per card group of the storefront (Home categories, Services
// features ...). Each page is a table of records; every record is a card on the landing page. The earlier single
// "Landing Page" page (texts & images) moves into the group as "Texts & Images".
return new class extends Migration {
    private const ACTIONS = ['view', 'create', 'update', 'delete'];

    // key suffix => [label, sort order]. The suffix is the card group id with "." written as "-".
    private const MODULES = [
        'home-perks' => ['Home · Hero highlights', 1],
        'home-stats' => ['Home · Platform numbers', 2],
        'home-categories' => ['Home · Food categories', 3],
        'home-services' => ['Home · What we bring', 4],
        'home-story' => ['Home · Story highlights', 5],
        'home-help' => ['Home · Help & partner cards', 6],
        'services-tools' => ['Services · Tool cards', 7],
        'services-points' => ['Services · Highlight chips', 8],
        'services-features' => ['Services · Feature cards', 9],
        'about-numbers' => ['About · Numbers', 10],
        'about-pillars' => ['About · Pillars', 11],
        'about-timeline' => ['About · Timeline', 12],
        'about-impact' => ['About · Impact numbers', 13],
    ];

    public function up()
    {
        $now = now();

        DB::table('navigation_items')->updateOrInsert(
            ['key' => 'landing-page'],
            ['parent_id' => null, 'label' => 'Landing Page', 'path' => null, 'icon' => 'PanelsTopLeft',
             'is_dropdown' => true, 'sort_order' => 8, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]
        );
        $parent = DB::table('navigation_items')->where('key', 'landing-page')->value('id');

        $keys = ['landing-page', 'landing-content'];

        foreach (self::MODULES as $slug => [$label, $sort]) {
            DB::table('navigation_items')->updateOrInsert(
                ['key' => "landing-$slug"],
                ['parent_id' => $parent, 'label' => $label, 'path' => "/landing/$slug", 'icon' => 'LayoutGrid',
                 'is_dropdown' => false, 'sort_order' => $sort, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]
            );
            $keys[] = "landing-$slug";
        }

        // the texts & images page now lives in the group
        DB::table('navigation_items')->updateOrInsert(
            ['key' => 'landing-content'],
            ['parent_id' => $parent, 'label' => 'Texts & Images', 'path' => '/landing/texts', 'icon' => 'Type',
             'is_dropdown' => false, 'sort_order' => 20, 'is_active' => true, 'updated_at' => $now]
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $names = [];
        foreach ($keys as $key) {
            if ($key === 'landing-page') {
                continue; // the group itself has no page
            }
            foreach (self::ACTIONS as $action) {
                $names[] = Permission::firstOrCreate(['name' => "$key.$action", 'guard_name' => 'web'])->name;
            }
        }

        foreach (['admin', 'super_admin'] as $roleName) {
            $role = Role::where('name', $roleName)->first();
            if (! $role) {
                continue;
            }
            foreach ($keys as $key) {
                $id = DB::table('navigation_items')->where('key', $key)->value('id');
                DB::table('role_navigations')->updateOrInsert(['role_id' => $role->id, 'navigation_item_id' => $id], ['created_at' => $now, 'updated_at' => $now]);
            }
            $role->givePermissionTo($names);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down()
    {
        $administration = DB::table('navigation_items')->where('key', 'administration')->value('id');
        DB::table('navigation_items')->where('key', 'landing-content')->update(['parent_id' => $administration, 'label' => 'Landing Page', 'path' => '/settings/landing', 'icon' => 'PanelsTopLeft']);

        $keys = array_map(fn ($slug) => "landing-$slug", array_keys(self::MODULES));
        $keys[] = 'landing-page';
        $ids = DB::table('navigation_items')->whereIn('key', $keys)->pluck('id');

        DB::table('role_navigations')->whereIn('navigation_item_id', $ids)->delete();
        DB::table('navigation_items')->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (array_slice($keys, 0, -1) as $key) {
            Permission::where('name', 'like', "$key.%")->get()->each->delete();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

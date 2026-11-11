<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

// Landing page content managed from the admin (Administration -> Landing Page).
// One row holds the replacements for the storefront's fixed texts and images; an empty row means
// "show the storefront exactly as it was built".
return new class extends Migration {
    private const NAV_KEY = 'landing-content';
    private const ACTIONS = ['view', 'create', 'update', 'delete'];

    public function up()
    {
        Schema::create('landing_contents', function (Blueprint $table) {
            $table->id();
            // {"texts": {"<original text>": {"en": "...", "km": "..."}}, "images": {"<original src>": "<url>"}}
            $table->longText('content')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();

        // admin module: Administration -> Landing Page
        $parent = DB::table('navigation_items')->where('key', 'administration')->value('id');
        DB::table('navigation_items')->updateOrInsert(
            ['key' => self::NAV_KEY],
            ['parent_id' => $parent, 'label' => 'Landing Page', 'path' => '/settings/landing', 'icon' => 'PanelsTopLeft',
             'is_dropdown' => false, 'sort_order' => 6, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]
        );
        $navId = DB::table('navigation_items')->where('key', self::NAV_KEY)->value('id');

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $names = [];
        foreach (self::ACTIONS as $action) {
            $names[] = Permission::firstOrCreate(['name' => self::NAV_KEY . ".$action", 'guard_name' => 'web'])->name;
        }

        // platform roles get the page switched on with all four actions (super admin is never restricted)
        foreach (['admin', 'super_admin'] as $roleName) {
            $role = Role::where('name', $roleName)->first();
            if (! $role) {
                continue;
            }
            DB::table('role_navigations')->updateOrInsert(['role_id' => $role->id, 'navigation_item_id' => $navId], ['created_at' => $now, 'updated_at' => $now]);
            if ($parent) {
                DB::table('role_navigations')->updateOrInsert(['role_id' => $role->id, 'navigation_item_id' => $parent], ['created_at' => $now, 'updated_at' => $now]);
            }
            $role->givePermissionTo($names);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down()
    {
        $navId = DB::table('navigation_items')->where('key', self::NAV_KEY)->value('id');
        if ($navId) {
            DB::table('role_navigations')->where('navigation_item_id', $navId)->delete();
            DB::table('navigation_items')->where('id', $navId)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::where('name', 'like', self::NAV_KEY . '.%')->get()->each->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Schema::dropIfExists('landing_contents');
    }
};

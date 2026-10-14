<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Platform-managed restaurant categories (cuisines) used by the /order filters, the category rail and
// business registration. The admin edits them in Administration -> Restaurant Categories.
return new class extends Migration {
    private const DEFAULTS = [
        // name, icon (Material Symbols), color, keywords used once to place the existing restaurants
        ['Burgers', 'lunch_dining', 'orange', 'burger|american|grill|fast'],
        ['Pizza & Pasta', 'local_pizza', 'rose', 'pizza|ital|pasta'],
        ['Khmer & Asian', 'ramen_dining', 'amber', 'khmer|asia|chin|japan|sushi|thai|korea|viet|ramen|noodle'],
        ['Indian & Curry', 'soup_kitchen', 'red', 'indian|curry|biryani'],
        ['Mexican', 'set_meal', 'yellow', 'mex|taco|burrito'],
        ['Healthy & Salads', 'rice_bowl', 'teal', 'health|vegan|salad|bowl'],
        ['Bakery & Desserts', 'bakery_dining', 'orange', 'bakery|dessert|cake|sweet'],
        ['Coffee & Drinks', 'coffee', 'stone', 'coffee|cafe|drink|boba|bubble|tea|juice'],
        ['Halal', 'nutrition', 'green', 'halal|kebab|turk|middle'],
        ['Groceries & Mart', 'storefront', 'emerald', 'grocery|mart|market'],
    ];

    public function up()
    {
        Schema::create('restaurant_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->string('icon', 60)->default('restaurant');
            $table->string('color', 30)->default('orange');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('restaurants', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('cuisine_type')->constrained('restaurant_categories')->nullOnDelete();
        });

        $now = now();
        foreach (self::DEFAULTS as $i => [$name, $icon, $color]) {
            DB::table('restaurant_categories')->insert([
                'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name), 'icon' => $icon, 'color' => $color,
                'sort_order' => $i + 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // place the restaurants that already exist
        $cats = DB::table('restaurant_categories')->pluck('id', 'name');
        foreach (DB::table('restaurants')->get(['id', 'cuisine_type']) as $r) {
            foreach (self::DEFAULTS as [$name, , , $keys]) {
                if (preg_match('/' . $keys . '/i', (string) $r->cuisine_type)) {
                    DB::table('restaurants')->where('id', $r->id)->update(['category_id' => $cats[$name]]);
                    break;
                }
            }
        }

        // admin module: Administration -> Restaurant Categories (assigned to the admin role; super admin sees everything)
        $parent = DB::table('navigation_items')->where('key', 'administration')->value('id');
        DB::table('navigation_items')->updateOrInsert(
            ['key' => 'restaurant-categories'],
            ['parent_id' => $parent, 'label' => 'Restaurant Categories', 'path' => '/settings/categories', 'icon' => 'Tags',
             'is_dropdown' => false, 'sort_order' => 5, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]
        );
        $navId = DB::table('navigation_items')->where('key', 'restaurant-categories')->value('id');
        $adminRole = DB::table('roles')->where('name', 'admin')->value('id');
        if ($adminRole) {
            DB::table('role_navigations')->updateOrInsert(['role_id' => $adminRole, 'navigation_item_id' => $navId], ['created_at' => $now, 'updated_at' => $now]);
            // the admin role needs the parent group visible too
            if ($parent) DB::table('role_navigations')->updateOrInsert(['role_id' => $adminRole, 'navigation_item_id' => $parent], ['created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down()
    {
        $navId = DB::table('navigation_items')->where('key', 'restaurant-categories')->value('id');
        if ($navId) {
            DB::table('role_navigations')->where('navigation_item_id', $navId)->delete();
            DB::table('navigation_items')->where('id', $navId)->delete();
        }
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });
        Schema::dropIfExists('restaurant_categories');
    }
};
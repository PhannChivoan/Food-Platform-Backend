<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Every branch can have its own menu (categories + dishes). The first branch of a restaurant is its "main" branch;
// a branch can copy the main branch's menu and then differ from it.
return new class extends Migration {
    public function up()
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->boolean('is_main')->default(false)->after('is_active');
        });
        Schema::table('menu_categories', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('restaurant_id')->constrained('branches')->nullOnDelete();
            $table->index(['restaurant_id', 'branch_id']);
        });
        Schema::table('menu_items', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('restaurant_id')->constrained('branches')->nullOnDelete();
            $table->index(['restaurant_id', 'branch_id']);
        });

        // existing data: the earliest branch of each restaurant becomes its main branch and owns the current menu
        foreach (DB::table('branches')->selectRaw('restaurant_id, MIN(id) as main_id')->groupBy('restaurant_id')->get() as $r) {
            DB::table('branches')->where('id', $r->main_id)->update(['is_main' => true]);
            DB::table('menu_categories')->where('restaurant_id', $r->restaurant_id)->update(['branch_id' => $r->main_id]);
            DB::table('menu_items')->where('restaurant_id', $r->restaurant_id)->update(['branch_id' => $r->main_id]);
        }
    }

    public function down()
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
        Schema::table('menu_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('is_main');
        });
    }
};
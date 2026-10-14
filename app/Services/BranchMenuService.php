<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Branch menus: which branch a dish belongs to, and copying the main branch's menu into another branch. */
class BranchMenuService
{
    public static function mainBranchId(int $restaurantId): ?int
    {
        $id = DB::table('branches')->where('restaurant_id', $restaurantId)->where('is_main', true)->value('id')
            ?? DB::table('branches')->where('restaurant_id', $restaurantId)->orderBy('id')->value('id');
        return $id ? (int) $id : null;
    }

    /** A requested branch must belong to the restaurant; otherwise the main branch is used. */
    public static function resolveBranchId(int $restaurantId, $requested): ?int
    {
        if ($requested && DB::table('branches')->where('id', (int) $requested)->where('restaurant_id', $restaurantId)->exists()) {
            return (int) $requested;
        }
        return self::mainBranchId($restaurantId);
    }

    /**
     * Copies the main branch's categories and dishes (with their option groups) into $branchId.
     * mode "missing": only adds what the branch does not have yet (matched by name); "replace": clears the branch's menu first.
     *
     * @return array{categories: int, items: int, skipped: int}
     */
    public static function copyFromMain(int $branchId, string $mode = 'missing'): array
    {
        $branch = DB::table('branches')->where('id', $branchId)->first();
        abort_unless($branch, 404, 'Branch not found');
        $mainId = self::mainBranchId((int) $branch->restaurant_id);
        abort_if(!$mainId || $mainId === $branchId, 422, 'This is the main branch. Pick another branch to load the main menu into.');

        return DB::transaction(function () use ($branch, $branchId, $mainId, $mode) {
            if ($mode === 'replace') {
                DB::table('menu_items')->where('branch_id', $branchId)->delete();
                DB::table('menu_categories')->where('branch_id', $branchId)->delete();
            }

            $counts = ['categories' => 0, 'items' => 0, 'skipped' => 0];
            $now = now();

            foreach (DB::table('menu_categories')->where('branch_id', $mainId)->orderBy('display_order')->get() as $cat) {
                $target = DB::table('menu_categories')->where('branch_id', $branchId)->where('name', $cat->name)->first();
                if (!$target) {
                    $targetId = DB::table('menu_categories')->insertGetId([
                        'restaurant_id' => $cat->restaurant_id, 'branch_id' => $branchId, 'name' => $cat->name, 'slug' => $cat->slug,
                        'description' => $cat->description, 'display_order' => $cat->display_order, 'is_active' => $cat->is_active,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $counts['categories']++;
                } else {
                    $targetId = $target->id;
                }

                foreach (DB::table('menu_items')->where('branch_id', $mainId)->where('category_id', $cat->id)->get() as $item) {
                    if (DB::table('menu_items')->where('branch_id', $branchId)->where('category_id', $targetId)->where('name', $item->name)->exists()) {
                        $counts['skipped']++;
                        continue;
                    }
                    $row = (array) $item;
                    unset($row['id']);
                    $newId = DB::table('menu_items')->insertGetId(array_merge($row, [
                        'branch_id' => $branchId, 'category_id' => $targetId, 'created_at' => $now, 'updated_at' => $now,
                    ]));
                    foreach (DB::table('menu_item_modifier_groups')->where('menu_item_id', $item->id)->pluck('modifier_group_id') as $gid) {
                        DB::table('menu_item_modifier_groups')->insert(['menu_item_id' => $newId, 'modifier_group_id' => $gid, 'created_at' => $now, 'updated_at' => $now]);
                    }
                    $counts['items']++;
                }
            }
            return $counts;
        });
    }
}
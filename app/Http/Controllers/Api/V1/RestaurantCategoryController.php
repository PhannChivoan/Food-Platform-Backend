<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Platform admin: manage the restaurant categories shown to customers and offered at business sign-up. */
class RestaurantCategoryController extends Controller
{
    private function rows()
    {
        return DB::table('restaurant_categories as c')
            ->leftJoin('restaurants as r', function ($j) {
                $j->on('r.category_id', '=', 'c.id')->whereNull('r.deleted_at');
            })
            ->groupBy('c.id', 'c.name', 'c.slug', 'c.icon', 'c.color', 'c.sort_order', 'c.is_active', 'c.created_at')
            ->orderBy('c.sort_order')->orderBy('c.name')
            ->get(['c.id', 'c.name', 'c.slug', 'c.icon', 'c.color', 'c.sort_order', 'c.is_active', 'c.created_at', DB::raw('COUNT(r.id) as restaurants_count')]);
    }

    public function index()
    {
        return response()->json(['data' => $this->rows()->map(fn ($c) => (array) $c + [])->all()]);
    }

    private function rules(?int $ignore = null): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('restaurant_categories', 'name')->ignore($ignore)],
            'icon' => 'nullable|string|max:60',
            'color' => 'nullable|string|max:30',
            'sort_order' => 'nullable|integer|min:0|max:9999',
            'is_active' => 'nullable|boolean',
        ];
    }

    public function store(Request $request)
    {
        $v = $request->validate($this->rules());
        $slug = Str::slug($v['name']);
        for ($i = 2; DB::table('restaurant_categories')->where('slug', $slug)->exists(); $i++) $slug = Str::slug($v['name']) . "-{$i}";

        $id = DB::table('restaurant_categories')->insertGetId([
            'name' => $v['name'], 'slug' => $slug, 'icon' => $v['icon'] ?? 'restaurant', 'color' => $v['color'] ?? 'orange',
            'sort_order' => $v['sort_order'] ?? ((int) DB::table('restaurant_categories')->max('sort_order') + 1),
            'is_active' => $v['is_active'] ?? true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['id' => $id, 'message' => 'Category created.'], 201);
    }

    public function update(Request $request, int $id)
    {
        abort_unless(DB::table('restaurant_categories')->where('id', $id)->exists(), 404, 'Category not found');
        $v = $request->validate($this->rules($id));
        $row = ['name' => $v['name'], 'updated_at' => now()];
        foreach (['icon', 'color', 'sort_order', 'is_active'] as $k) if (array_key_exists($k, $v)) $row[$k] = $v[$k];
        DB::table('restaurant_categories')->where('id', $id)->update($row);

        // keep the visible cuisine label of its restaurants in step with the category name
        DB::table('restaurants')->where('category_id', $id)->update(['cuisine_type' => $v['name']]);
        return response()->json(['message' => 'Category updated.']);
    }

    public function destroy(int $id)
    {
        abort_unless(DB::table('restaurant_categories')->where('id', $id)->exists(), 404, 'Category not found');
        $used = DB::table('restaurants')->where('category_id', $id)->whereNull('deleted_at')->count();
        abort_if($used > 0, 422, "This category still has {$used} restaurant(s). Move them to another category first.");
        DB::table('restaurant_categories')->where('id', $id)->delete();
        return response()->json(['message' => 'Category deleted.']);
    }
}
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Services\BranchMenuService;
use Illuminate\Support\Facades\DB;

/**
 * Unauthenticated storefront endpoints for the customer-facing /order pages.
 * Uses the query builder on purpose: the Eloquent RestaurantScope returns
 * nothing for guests, and only verified restaurants should be public anyway.
 */
class PublicRestaurantController extends Controller
{
    /** Storefront data barely changes second to second, so each answer is kept for 30 s (shared by all visitors). */
    private function cached(string $key, \Closure $build)
    {
        return response()->json(Cache::remember("public:{$key}", 30, $build));
    }

    public function index(Request $request)
    {
        return $this->cached('restaurants:' . md5(json_encode($request->query())), fn () => $this->buildIndex($request));
    }

    private function buildIndex(Request $request): array
    {
        $search = trim((string) $request->query('search', ''));
        $cuisine = trim((string) $request->query('cuisine', ''));

        $restaurants = DB::table('restaurants')
            ->where('status', 'verified')
            ->whereNull('deleted_at')
            ->when($search !== '', fn ($q) => $q->where(function ($sq) use ($search) {
                $sq->where('name', 'like', "%{$search}%")
                    ->orWhere('cuisine_type', 'like', "%{$search}%");
            }))
            ->when($cuisine !== '', fn ($q) => $q->where('cuisine_type', $cuisine))
            ->orderBy('name')
            ->get();

        $ids = $restaurants->pluck('id');

        $branches = DB::table('branches')
            ->whereIn('restaurant_id', $ids)->where('is_active', true)
            ->select('id', 'restaurant_id', 'name', 'address', 'city', 'is_main')
            ->get()->groupBy('restaurant_id');

        $mainIds = DB::table('branches')->whereIn('restaurant_id', $ids)->where('is_main', true)->pluck('id');
        $items = DB::table('menu_items')
            ->whereIn('restaurant_id', $ids)->where('is_available', true)
            ->where(fn ($q) => $q->whereIn('branch_id', $mainIds)->orWhereNull('branch_id'))
            ->select('id', 'restaurant_id', 'category_id', 'name', 'description', 'price', 'discounted_price', 'image', 'is_featured', 'prep_time_minutes')
            ->orderByDesc('is_featured')->orderBy('id')
            ->get()->groupBy('restaurant_id');

        $data = $restaurants->map(function ($r) use ($branches, $items) {
            $rBranches = $branches->get($r->id, collect());
            $rItems = $items->get($r->id, collect());
            $top = $rItems->first();
            $prep = $rItems->avg('prep_time_minutes') ?: \App\Services\PlatformSettings::int('prep_time_minutes', 15);

            return [
                'id' => $r->id,
                'name' => $r->name,
                'slug' => $r->slug,
                'description' => $r->description,
                'cuisine_type' => $r->cuisine_type,
                'category_id' => $r->category_id,
                'logo_url' => $r->logo ? asset('storage/' . $r->logo) : null,
                'cover_url' => $r->cover_image ? asset('storage/' . $r->cover_image) : null,
                'branches_count' => $rBranches->count(),
                'main_branch' => $rBranches->firstWhere('is_main', true) ?? $rBranches->first(),
                'menu_items_count' => $rItems->count(),
                'prep_minutes' => (int) round($prep),
                'preview_items' => $rItems->take(12)->map(fn ($i) => $this->itemCard($i))->values(),
                'top_item' => $top ? [
                    'id' => $top->id,
                    'name' => $top->name,
                    'price' => (float) ($top->discounted_price ?? $top->price),
                    'image_url' => $top->image ? asset('storage/' . $top->image) : null,
                ] : null,
            ];
        })->values();

        return [
            'total' => $data->count(),
            'data' => $data,
        ];
    }

    /** Active restaurant categories (managed in the admin) with how many verified restaurants are in each. */
    public function categories()
    {
        return $this->cached('categories', fn () => $this->buildCategories());
    }

    private function buildCategories(): array
    {
        $counts = DB::table('restaurants')->where('status', 'verified')->whereNull('deleted_at')
            ->selectRaw('category_id, COUNT(*) c')->groupBy('category_id')->pluck('c', 'category_id');
        $rows = DB::table('restaurant_categories')->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'icon' => $c->icon, 'color' => $c->color, 'restaurants_count' => (int) ($counts[$c->id] ?? 0)]);
        return ['data' => $rows->values()];
    }

    /** Cuisine categories with how many verified restaurants offer each. */
    public function cuisines()
    {
        $rows = DB::table('restaurants')
            ->where('status', 'verified')->whereNull('deleted_at')
            ->select('cuisine_type as name', DB::raw('COUNT(*) as restaurants_count'))
            ->groupBy('cuisine_type')->orderBy('cuisine_type')
            ->get();

        return response()->json(['data' => $rows]);
    }

    private function itemCard($i): array
    {
        return [
            'id' => $i->id,
            'restaurant_id' => $i->restaurant_id,
            'name' => $i->name,
            'description' => $i->description,
            'price' => (float) $i->price,
            'discounted_price' => $i->discounted_price !== null ? (float) $i->discounted_price : null,
            'image_url' => $i->image ? asset('storage/' . $i->image) : null,
            'is_featured' => (bool) ($i->is_featured ?? false),
            'prep_time_minutes' => $i->prep_time_minutes ?? null,
        ];
    }

    private function restaurantCard($r): array
    {
        return [
            'id' => $r->id,
            'name' => $r->name,
            'cuisine_type' => $r->cuisine_type,
            'description' => $r->description,
            'logo_url' => $r->logo ? asset('storage/' . $r->logo) : null,
            'cover_url' => $r->cover_image ? asset('storage/' . $r->cover_image) : null,
        ];
    }

    /** One restaurant with its branches and full available menu, grouped by category. */
    public function show(Request $request, int $id)
    {
        $branch = $request->integer('branch') ?: null;
        return $this->cached("restaurant:{$id}:{$branch}", fn () => $this->buildShow($id, $branch));
    }

    private function buildShow(int $id, ?int $requestedBranch = null): array
    {
        $r = DB::table('restaurants')->where('id', $id)->where('status', 'verified')->whereNull('deleted_at')->first();
        abort_unless($r, 404, 'Restaurant not found');

        $branches = DB::table('branches')->where('restaurant_id', $id)
            ->select('id', 'name', 'address', 'city', 'phone', 'is_active', 'is_main')->orderByDesc('is_main')->orderBy('id')->get();

        // the menu belongs to a branch: the requested open branch, otherwise the main one
        $branchId = $requestedBranch && $branches->firstWhere('id', $requestedBranch)?->is_active
            ? $requestedBranch
            : BranchMenuService::mainBranchId($id);

        $items = DB::table('menu_items')->where('restaurant_id', $id)->where('is_available', true)
            ->where(fn ($q) => $q->where('branch_id', $branchId)->orWhereNull('branch_id'))
            ->orderByDesc('is_featured')->orderBy('id')->get();
        $withOptions = DB::table('menu_item_modifier_groups')
            ->whereIn('menu_item_id', $items->pluck('id'))->pluck('menu_item_id')->unique()->flip();

        $categories = DB::table('menu_categories')->where('restaurant_id', $id)->where('is_active', true)
            ->where(fn ($q) => $q->where('branch_id', $branchId)->orWhereNull('branch_id'))
            ->orderBy('display_order')->get()
            ->map(function ($c) use ($items, $withOptions) {
                return [
                    'id' => $c->id,
                    'name' => $c->name,
                    'description' => $c->description,
                    'items' => $items->where('category_id', $c->id)->map(function ($i) use ($withOptions) {
                        return $this->itemCard($i) + ['has_options' => $withOptions->has($i->id)];
                    })->values(),
                ];
            })->filter(fn ($c) => $c['items']->isNotEmpty())->values();

        return $this->restaurantCard($r) + [
            'branch_id' => $branchId,
            'branches' => $branches,
            'categories' => $categories,
        ];
    }

    /** One dish with the option groups its store configured, plus more dishes from the same store. */
    public function item(int $id)
    {
        return $this->cached("item:{$id}", fn () => $this->buildItem($id));
    }

    private function buildItem(int $id): array
    {
        $item = DB::table('menu_items')->where('id', $id)->where('is_available', true)->first();
        abort_unless($item, 404, 'Item not found');

        $r = DB::table('restaurants')->where('id', $item->restaurant_id)->where('status', 'verified')->whereNull('deleted_at')->first();
        abort_unless($r, 404, 'Restaurant not found');

        $groupIds = DB::table('menu_item_modifier_groups')->where('menu_item_id', $id)->pluck('modifier_group_id');
        $mods = DB::table('modifiers')->whereIn('modifier_group_id', $groupIds)->orderBy('id')->get()->groupBy('modifier_group_id');
        $groups = DB::table('modifier_groups')->whereIn('id', $groupIds)->get()->map(fn ($g) => [
            'id' => $g->id,
            'name' => $g->name,
            'selection_type' => $g->selection_type,
            'min_selections' => (int) $g->min_selections,
            'max_selections' => (int) $g->max_selections,
            'modifiers' => $mods->get($g->id, collect())->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'price_adjustment' => (float) $m->price_adjustment,
                'is_default' => (bool) $m->is_default,
            ])->values(),
        ])->values();

        $more = DB::table('menu_items')->where('restaurant_id', $item->restaurant_id)
            ->where('branch_id', $item->branch_id)
            ->where('is_available', true)->where('id', '!=', $id)
            ->orderByRaw('category_id = ? desc', [$item->category_id])->orderBy('id')
            ->limit(12)->get()->map(fn ($i) => $this->itemCard($i))->values();

        $branches = DB::table('branches')->where('restaurant_id', $r->id)->where('is_active', true)
            ->select('id', 'name', 'address', 'city')->get();

        return [
            'item' => $this->itemCard($item) + ['branch_id' => $item->branch_id],
            'restaurant' => $this->restaurantCard($r),
            'branch' => $item->branch_id ? DB::table('branches')->where('id', $item->branch_id)->first(['id', 'name']) : null,
            'branches' => $branches,
            'modifier_groups' => $groups,
            'more_items' => $more,
        ];
    }
}
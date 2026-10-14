<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\Modifier;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use App\Models\Branch;
use App\Services\BranchMenuService;

class MenuController extends Controller
{
    const IMAGE_DIR = 'menu_items';

    protected function handleItemImage($file): string
    {
        return $file->store(self::IMAGE_DIR, 'public');
    }

    protected function deleteItemImage($path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }

    public function categoriesIndex(Request $request)
    {
        $validated = $request->validate([
            'restaurant_id' => 'nullable|integer|exists:restaurants,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'is_active' => 'nullable|boolean',
            'search' => 'nullable|string',
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $page = $validated['page'] ?? 1;
        $limit = $validated['limit'] ?? 15;
        $search = trim((string) ($validated['search'] ?? ''));

        $query = MenuCategory::with('restaurant:id,name')
            ->with('branch:id,name')
            ->withCount('menuItems');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('restaurant', function ($rq) use ($search) {
                        $rq->where('name', 'like', "%{$search}%");
                    });
            });
        }

        if (!empty($validated['restaurant_id'])) {
            $query->where('restaurant_id', $validated['restaurant_id']);
        }

        if (!empty($validated['branch_id'])) {
            $query->where('branch_id', $validated['branch_id']);
        }

        if (isset($validated['is_active'])) {
            $query->where('is_active', $validated['is_active']);
        }

        $categories = $query->orderBy('display_order')
            ->orderByDesc('id')
            ->paginate($limit, ['*'], 'page', $page);

        return response()->json([
            'stats' => [
                'total' => MenuCategory::count(),
                'active' => MenuCategory::where('is_active', true)->count(),
                'inactive' => MenuCategory::where('is_active', false)->count(),
                'restaurants' => MenuCategory::distinct()->count('restaurant_id'),
            ],
            'total' => $categories->total(),
            'page' => (int) $page,
            'limit' => (int) $limit,
            'data' => $categories->getCollection()->map(fn ($cat) => [
                'key' => "category-{$cat->id}",
                'id' => $cat->id,
                'restaurant_id' => $cat->restaurant_id,
                'branch_id' => $cat->branch_id,
                'branch_name' => $cat->branch?->name,
                'branch_is_main' => (bool) ($cat->branch?->is_main),
                'name' => $cat->name,
                'slug' => $cat->slug,
                'description' => $cat->description,
                'display_order' => $cat->display_order,
                'is_active' => (bool) $cat->is_active,
                'restaurant_name' => $cat->restaurant?->name,
                'menu_items_count' => $cat->menu_items_count,
            ])->values(),
        ]);
    }

    public function categoriesShow($restaurant, $category)
    {
        $category = MenuCategory::with('restaurant:id,name')
            ->withCount('menuItems')
            ->with([
                'menuItems:id,restaurant_id,category_id,name,slug,price,discounted_price,image,is_available,is_featured,prep_time_minutes',
            ])
            ->findOrFail($category);

        abort_if($category->restaurant_id != $restaurant, 404);

        return response()->json([
            'key' => "category-{$category->id}",
            'id' => $category->id,
            'restaurant_id' => $category->restaurant_id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'display_order' => $category->display_order,
            'is_active' => (bool) $category->is_active,
            'restaurant_name' => $category->restaurant?->name,
            'menu_items_count' => $category->menu_items_count,
            'menu_items' => $category->menuItems->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'slug' => $item->slug,
                'price' => (float) $item->price,
                'discounted_price' => $item->discounted_price !== null ? (float) $item->discounted_price : null,
                'image_url' => $item->image_url,
                'is_available' => (bool) $item->is_available,
                'is_featured' => (bool) $item->is_featured,
                'prep_time_minutes' => $item->prep_time_minutes,
            ])->values(),
            'created_at' => $category->created_at?->toISOString(),
            'updated_at' => $category->updated_at?->toISOString(),
        ]);
    }

    public function itemsIndex(Request $request)
    {
        $validated = $request->validate([
            'search' => 'nullable|string',
            'restaurant_id' => 'nullable|integer|exists:restaurants,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'category_id' => 'nullable|integer|exists:menu_categories,id',
            'is_available' => 'nullable|boolean',
            'is_featured' => 'nullable|boolean',
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $search = $validated['search'] ?? '';
        $page = $validated['page'] ?? 1;
        $limit = $validated['limit'] ?? 15;

        $items = MenuItem::with(['restaurant:id,name', 'category:id,name'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($sq) use ($search) {
                    $sq->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhereHas('restaurant', function ($rq) use ($search) {
                            $rq->where('name', 'like', "%{$search}%");
                        });
                });
            })
            ->when(isset($validated['restaurant_id']), fn ($q) => $q->where('restaurant_id', $validated['restaurant_id']))
            ->when(isset($validated['branch_id']), fn ($q) => $q->where('branch_id', $validated['branch_id']))
            ->when(isset($validated['category_id']), fn ($q) => $q->where('category_id', $validated['category_id']))
            ->when(isset($validated['is_available']), fn ($q) => $q->where('is_available', $validated['is_available']))
            ->when(isset($validated['is_featured']), fn ($q) => $q->where('is_featured', $validated['is_featured']))
            ->orderByDesc('id')
            ->paginate($limit, ['*'], 'page', $page);

        return response()->json([
            'stats' => [
                'total' => MenuItem::count(),
                'available' => MenuItem::where('is_available', true)->count(),
                'unavailable' => MenuItem::where('is_available', false)->count(),
                'featured' => MenuItem::where('is_featured', true)->count(),
                'missing_images' => MenuItem::whereNull('image')->orWhere('image', '')->count(),
            ],
            'total' => $items->total(),
            'page' => (int) $page,
            'limit' => (int) $limit,
            'data' => $items->getCollection()->map(fn ($item) => $this->formatMenuItem($item))->values(),
        ]);
    }

    private function formatMenuItem(MenuItem $item): array
    {
        return [
            'key' => "item-{$item->id}",
            'id' => $item->id,
            'restaurant_id' => $item->restaurant_id,
            'category_id' => $item->category_id,
            'name' => $item->name,
            'slug' => $item->slug,
            'description' => $item->description,
            'price' => (float) $item->price,
            'discounted_price' => $item->discounted_price !== null ? (float) $item->discounted_price : null,
            'image_url' => $item->image_url,
            'is_available' => (bool) $item->is_available,
            'is_featured' => (bool) $item->is_featured,
            'prep_time_minutes' => $item->prep_time_minutes,
            'options' => $item->options ?? [],
            'restaurant_name' => $item->restaurant?->name,
            'category_name' => $item->category?->name,
        ];
    }

    // ---------- Categories ----------
    public function storeCategory(Request $request, Restaurant $restaurant)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'display_order' => 'nullable|integer',
        ]);
        $category = $restaurant->menuCategories()->create([
            ...$validated,
            'branch_id' => BranchMenuService::resolveBranchId($restaurant->id, $request->input('branch_id')),
            'slug' => Str::slug($validated['name']),
        ]);
        return response()->json($category, 201);
    }

    public function updateCategory(Request $request, Restaurant $restaurant, MenuCategory $category)
    {
        abort_if($category->restaurant_id !== $restaurant->id, 404);
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'display_order' => 'nullable|integer',
            'is_active' => 'sometimes|boolean',
        ]);
        $category->update($validated);
        return response()->json($category);
    }

    public function deleteCategory(Restaurant $restaurant, MenuCategory $category)
    {
        abort_if($category->restaurant_id !== $restaurant->id, 404);
        $category->delete();
        return response()->json(['message' => 'Category deleted']);
    }

        // ---------- Menu Items ----------
    public function index(Request $request, Restaurant $restaurant)
    {
        $branchId = $request->filled('branch_id') ? (int) $request->input('branch_id') : null;
        $menu = $restaurant->menuCategories()
                           ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                           ->select(['id', 'restaurant_id', 'name', 'slug', 'description', 'display_order', 'is_active'])
                           ->with([
                               'menuItems' => fn($q) => $q
                                   ->when($branchId, fn ($qq) => $qq->where('branch_id', $branchId))
                                   ->select(['id', 'restaurant_id', 'category_id', 'name', 'slug', 'description', 'price', 'discounted_price', 'image', 'is_available', 'is_featured', 'prep_time_minutes'])
                                   ->with([
                                       'modifierGroups:id,name',
                                       'modifierGroups.modifiers:id,modifier_group_id,name,price_adjustment,is_default',
                                   ]),
                           ])
                           ->orderBy('display_order')
                           ->get();
        return response()->json($menu);
    }

    public function storeItem(Request $request, Restaurant $restaurant)
    {
        $validated = $this->validateItemFields($request, false);

        $item = MenuItem::create([
            'restaurant_id' => $restaurant->id,
            'branch_id' => BranchMenuService::resolveBranchId($restaurant->id, $request->input('branch_id')),
            ...Arr::except($validated, ['image_file', 'modifier_group_ids']),
            'slug' => Str::slug($validated['name']),
        ]);

        if (isset($validated['image_path'])) {
            $item->image = $validated['image_path'];
            $item->save();
        }

                        if (!empty($validated['modifier_group_ids'])) {
            $item->modifierGroups()->sync($validated['modifier_group_ids']);
        }
        $item->refresh();
        return response()->json($this->formatMenuItem($item), 201);
    }

    public function updateItem(Request $request, Restaurant $restaurant, MenuItem $item)
    {
        abort_if($item->restaurant_id !== $restaurant->id, 404);
        $validated = $this->validateItemFields($request, true);

        $item->fill(Arr::except($validated, ['image_file', 'modifier_group_ids']));

        if (isset($validated['image_path'])) {
            if ($item->image) {
                Storage::disk('public')->delete($item->image);
            }
            $item->image = $validated['image_path'];
        }

        if (isset($validated['remove_image'])) {
            if ($item->image) {
                Storage::disk('public')->delete($item->image);
            }
            $item->image = null;
        }

        $item->save();

        if (isset($validated['modifier_group_ids'])) {
            $item->modifierGroups()->sync($validated['modifier_group_ids']);
        }
                        return response()->json($this->formatMenuItem($item));
    }

    /**
     * Shared validation for create/update, handling both file uploads
     * and the string path sent by the image editor.
     */
    protected function validateItemFields(Request $request, bool $isUpdate): array
    {
        $data = $request->all();

                // image_file accepts an uploaded file (from the frontend Upload component)
        // image accepts a string path (when the editor sends an existing path)
        // remove_image = "clear" to delete the current image
        $rules = [
            'category_id' => $isUpdate ? 'sometimes|exists:menu_categories,id' : 'required|exists:menu_categories,id',
            'name' => $isUpdate ? 'sometimes|string|max:255' : 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => $isUpdate ? 'sometimes|numeric|min:0' : 'required|numeric|min:0',
            'discounted_price' => 'nullable|numeric|min:0',
            'prep_time_minutes' => 'nullable|integer',
            'is_available' => 'sometimes|in:0,1,true,false',
            'is_featured' => 'sometimes|in:0,1,true,false',
            'options' => 'nullable|array',
            'modifier_group_ids' => 'nullable|array',
            'remove_image' => 'sometimes|in:clear',
            'image' => 'nullable|string',
            'image_file' => 'nullable|image|mimes:jpeg,png,webp|max:5120',
        ];

        $validated = $request->validate($rules);

        // Handle the uploaded file
        if ($request->hasFile('image_file')) {
            $validated['image_path'] = $this->handleItemImage($request->file('image_file'));
        } elseif (isset($data['image']) && is_string($data['image']) && $data['image'] !== '' && !Str::startsWith($data['image'], 'http')) {
            // A string path was provided (e.g. from an editor sending a previously uploaded path)
            $validated['image_path'] = $data['image'];
        }

        return $validated;
    }

    public function deleteItem(Restaurant $restaurant, MenuItem $item)
    {
        abort_if($item->restaurant_id !== $restaurant->id, 404);
        $item->delete();
        return response()->json(['message' => 'Item deleted']);
    }

    public function toggleAvailability(Request $request, Restaurant $restaurant, MenuItem $item)
    {
        abort_if($item->restaurant_id !== $restaurant->id, 404);
        $item->update(['is_available' => !$item->is_available]);
        return response()->json(['is_available' => $item->is_available]);
    }

    public function bulkToggleAvailability(Request $request, Restaurant $restaurant)
    {
        $request->validate(['item_ids' => 'required|array', 'available' => 'required|boolean']);
        MenuItem::whereIn('id', $request->item_ids)->where('restaurant_id', $restaurant->id)
                ->update(['is_available' => $request->available]);
        return response()->json(['message' => 'Bulk update complete']);
    }

    // ---------- Modifier Groups ----------
    /** Fill a branch's menu from the restaurant's main branch (adds what is missing, or replaces the branch menu). */
    public function copyMenuFromMain(Request $request, Branch $branch)
    {
        $v = $request->validate(['mode' => 'nullable|in:missing,replace']);
        $counts = BranchMenuService::copyFromMain($branch->id, $v['mode'] ?? 'missing');
        return response()->json(['message' => "Loaded {$counts['items']} dish(es) and {$counts['categories']} categor(ies) from the main branch.", 'copied' => $counts]);
    }

    public function modifierGroupsIndex(Request $request)
    {
        $validated = $request->validate([
            'restaurant_id' => 'nullable|integer|exists:restaurants,id',
            'search' => 'nullable|string',
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $page = $validated['page'] ?? 1;
        $limit = $validated['limit'] ?? 15;
        $search = trim((string) ($validated['search'] ?? ''));

        $query = ModifierGroup::with('restaurant:id,name')
            ->withCount('modifiers');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhereHas('restaurant', function ($rq) use ($search) {
                        $rq->where('name', 'like', "%{$search}%");
                    });
            });
        }

        if (!empty($validated['restaurant_id'])) {
            $query->where('restaurant_id', $validated['restaurant_id']);
        }

        $groups = $query->orderByDesc('id')
            ->paginate($limit, ['*'], 'page', $page);

        return response()->json([
            'stats' => [
                'total' => ModifierGroup::count(),
                'single' => ModifierGroup::where('selection_type', 'single')->count(),
                'multiple' => ModifierGroup::where('selection_type', 'multiple')->count(),
                'restaurants' => ModifierGroup::distinct()->count('restaurant_id'),
            ],
            'total' => $groups->total(),
            'page' => (int) $page,
            'limit' => (int) $limit,
            'data' => $groups->getCollection()->map(fn ($g) => [
                'key' => "group-{$g->id}",
                'id' => $g->id,
                'restaurant_id' => $g->restaurant_id,
                'name' => $g->name,
                'selection_type' => $g->selection_type,
                'min_selections' => $g->min_selections,
                'max_selections' => $g->max_selections,
                'restaurant_name' => $g->restaurant?->name,
                'modifiers_count' => $g->modifiers_count,
            ])->values(),
        ]);
    }

    public function modifierGroupsShow(ModifierGroup $group)
    {
        $group->load([
            'restaurant:id,name',
            'modifiers:id,modifier_group_id,name,price_adjustment,is_default',
            'menuItems:id,name',
        ]);

        return response()->json([
            'key' => "group-{$group->id}",
            'id' => $group->id,
            'restaurant_id' => $group->restaurant_id,
            'name' => $group->name,
            'selection_type' => $group->selection_type,
            'min_selections' => $group->min_selections,
            'max_selections' => $group->max_selections,
            'restaurant_name' => $group->restaurant?->name,
            'modifiers' => $group->modifiers->values(),
            'menu_items' => $group->menuItems->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
            ])->values(),
            'created_at' => $group->created_at?->toISOString(),
            'updated_at' => $group->updated_at?->toISOString(),
        ]);
    }

    public function getModifierGroups(Restaurant $restaurant)
    {
        return response()->json($restaurant->modifierGroups()->with('modifiers')->get());
    }

    public function storeModifierGroup(Request $request, Restaurant $restaurant)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'selection_type' => 'sometimes|in:single,multiple',
            'min_selections' => 'sometimes|integer',
            'max_selections' => 'sometimes|integer',
        ]);
        $group = $restaurant->modifierGroups()->create($validated);
        return response()->json($group, 201);
    }

    public function updateModifierGroup(Request $request, ModifierGroup $group)
    {
        $group->update($request->validate([
            'name' => 'sometimes|string',
            'selection_type' => 'sometimes|in:single,multiple',
            'min_selections' => 'sometimes|integer',
            'max_selections' => 'sometimes|integer',
        ]));
        return response()->json($group);
    }

    public function deleteModifierGroup(ModifierGroup $group)
    {
        $group->delete();
        return response()->json(['message' => 'Modifier group deleted']);
    }

    // ---------- Individual Modifiers ----------
    public function storeModifier(Request $request, ModifierGroup $group)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'price_adjustment' => 'nullable|numeric|min:0',
            'is_default' => 'sometimes|boolean',
        ]);

        $modifier = $group->modifiers()->create([
            'name' => $validated['name'],
            'price_adjustment' => $validated['price_adjustment'] ?? 0,
            'is_default' => $validated['is_default'] ?? false,
        ]);

        return response()->json($modifier, 201);
    }

    public function updateModifier(Request $request, Modifier $modifier)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'price_adjustment' => 'sometimes|numeric|min:0',
            'is_default' => 'sometimes|boolean',
        ]);

        $modifier->update($validated);
        return response()->json($modifier);
    }

    public function deleteModifier(Modifier $modifier)
    {
        $modifier->delete();
        return response()->json(['message' => 'Modifier deleted']);
    }

    // Import/Export (stubs)
    public function import(Request $request, Restaurant $restaurant) { /* ... */ }
    public function export(Restaurant $restaurant) { /* ... */ }
}
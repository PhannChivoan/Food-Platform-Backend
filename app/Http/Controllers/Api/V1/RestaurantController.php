<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RestaurantController extends Controller
{
    private const LOGO_DISK = 'public';
    private const LOGO_DIR = 'restaurants/logos';

    private const BRANCH_SELECT = [
        'id', 'restaurant_id', 'name', 'address', 'city', 'postal_code',
        'phone', 'operating_hours', 'holiday_dates', 'is_active', 'is_main',
    ];

    // ─── READ ─────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $validated = $request->validate([
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:200',
            'search' => 'nullable|string|max:100',
            'status' => 'nullable|string|in:pending,verified,suspended,closed',
        ]);

        $limit = $validated['limit'] ?? 12;
        $page = $validated['page'] ?? 1;
        $search = trim((string) ($validated['search'] ?? ''));

        $restaurants = Restaurant::query()
            ->with([
                'owner:id,name,email',
                'branches' => function ($query) {
                    $query->select(self::BRANCH_SELECT)
                        ->addSelect(DB::raw('ST_Y(location) as latitude'))
                        ->addSelect(DB::raw('ST_X(location) as longitude'));
                },
            ])
            ->withCount([
                'branches',
                'branches as active_branches_count' => function ($query) {
                    $query->where('is_active', true);
                },
            ])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($sq) use ($search) {
                    $sq->where('name', 'like', "%{$search}%")
                        ->orWhere('cuisine_type', 'like', "%{$search}%")
                        ->orWhereHas('branches', function ($bq) use ($search) {
                            $bq->where('city', 'like', "%{$search}%")
                                ->orWhere('address', 'like', "%{$search}%");
                        });
                });
            })
            ->when(!empty($validated['status']), function ($query) use ($validated) {
                $query->where('status', $validated['status']);
            })
            ->orderByDesc('created_at')
            ->paginate($limit, ['*'], 'page', $page);

        return response()->json([
            'stats' => [
                'total' => Restaurant::count(),
                'pending' => Restaurant::where('status', 'pending')->count(),
                'verified' => Restaurant::where('status', 'verified')->count(),
                'suspended' => Restaurant::where('status', 'suspended')->count(),
                'active_branches' => Branch::where('is_active', true)->count(),
            ],
            'total' => $restaurants->total(),
            'page' => (int) $page,
            'limit' => (int) $limit,
            'data' => $restaurants->getCollection()
                ->map(fn ($restaurant) => $this->formatRestaurant($restaurant))
                ->values(),
        ]);
    }

    public function show(Restaurant $restaurant)
    {
        return response()->json($this->formatRestaurant($this->loadForResponse($restaurant)));
    }

    // ─── RESTAURANT CRUD ──────────────────────────────────────────

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'cuisine_type' => 'nullable|string|max:100',
            'category_id' => 'nullable|integer|exists:restaurant_categories,id',
            'description' => 'nullable|string|max:2000',
            'owner_id' => 'nullable|integer|exists:users,id',
            'status' => 'sometimes|in:pending,verified,suspended,closed',
            'settings' => 'nullable|array',
            'logo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        // the category drives the filters; its name is the cuisine label customers read
        $catName = !empty($validated['category_id']) ? DB::table('restaurant_categories')->where('id', $validated['category_id'])->value('name') : null;
        abort_if(!$catName && empty($validated['cuisine_type']), 422, 'Please choose a category.');

        $restaurant = Restaurant::create([
            'owner_id' => $validated['owner_id'] ?? auth()->id(),
            'category_id' => $validated['category_id'] ?? null,
            'name' => $validated['name'],
            'slug' => $this->uniqueSlug($validated['name']),
            'cuisine_type' => $catName ?? $validated['cuisine_type'],
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'] ?? 'pending',
            'settings' => $validated['settings'] ?? null,
        ]);

        if ($request->hasFile('logo')) {
            $restaurant->update([
                'logo' => $this->storeLogo($request->file('logo')),
            ]);
        }

        return response()->json(
            $this->formatRestaurant($this->loadForResponse($restaurant)),
            201,
        );
    }

    public function update(Request $request, Restaurant $restaurant)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'cuisine_type' => 'sometimes|string|max:100',
            'category_id' => 'nullable|integer|exists:restaurant_categories,id',
            'description' => 'nullable|string|max:2000',
            'status' => 'sometimes|in:pending,verified,suspended,closed',
            'settings' => 'nullable|array',
            'logo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'remove_logo' => 'nullable|boolean',
        ]);

        $payload = collect($validated)->except(['logo', 'remove_logo'])->all();
        if (!empty($payload['category_id'])) {
            $payload['cuisine_type'] = DB::table('restaurant_categories')->where('id', $payload['category_id'])->value('name');
        }

        if (isset($payload['name']) && $payload['name'] !== $restaurant->name) {
            $payload['slug'] = $this->uniqueSlug($payload['name']);
        }

        if (!empty($validated['remove_logo'])) {
            $this->deleteLogo($restaurant->logo);
            $payload['logo'] = null;
        }

        if (!empty($payload)) {
            $restaurant->update($payload);
        }

        if ($request->hasFile('logo')) {
            $restaurant->update([
                'logo' => $this->storeLogo($request->file('logo'), $restaurant->logo),
            ]);
        }

        return response()->json(
            $this->formatRestaurant($this->loadForResponse($restaurant)),
        );
    }

    public function destroy(Restaurant $restaurant)
    {
        $restaurant->delete();

        return response()->json(['message' => 'Restaurant deleted']);
    }

    // ─── LIFECYCLE (pending → verified ⇄ suspended → closed) ─────

    public function verify(Restaurant $restaurant)
    {
        $restaurant->update([
            'status' => 'verified',
            'verified_at' => now(),
        ]);

        return response()->json(
            $this->formatRestaurant($this->loadForResponse($restaurant)),
        );
    }

    public function suspend(Restaurant $restaurant)
    {
        $restaurant->update(['status' => 'suspended']);

        return response()->json(
            $this->formatRestaurant($this->loadForResponse($restaurant)),
        );
    }

    public function close(Restaurant $restaurant)
    {
        $restaurant->update(['status' => 'closed']);

        return response()->json(
            $this->formatRestaurant($this->loadForResponse($restaurant)),
        );
    }

    // ─── BRANCHES ─────────────────────────────────────────────────

    public function addBranch(Request $request, Restaurant $restaurant)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'required|string|max:500',
            'city' => 'required|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'phone' => 'nullable|string|max:30',
            'operating_hours' => 'nullable|array',
            'holiday_dates' => 'nullable|array',
            'is_active' => 'sometimes|boolean',
        ]);

        $branch = $restaurant->branches()->create([
            'name' => $validated['name'],
            'address' => $validated['address'],
            'city' => $validated['city'],
            'postal_code' => $validated['postal_code'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'operating_hours' => $validated['operating_hours'] ?? null,
            'holiday_dates' => $validated['holiday_dates'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'is_main' => !$restaurant->branches()->exists(), // the first branch is the main one
            'location' => $this->locationRaw($validated['lng'], $validated['lat']),
        ]);

        return response()->json($this->formatBranch($this->freshBranch($branch->id)), 201);
    }

    public function updateBranch(Request $request, Restaurant $restaurant, Branch $branch)
    {
        // Ensure branch belongs to restaurant
        abort_if($branch->restaurant_id !== $restaurant->id, 404);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'address' => 'sometimes|string|max:500',
            'city' => 'sometimes|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'lat' => 'sometimes|numeric|between:-90,90',
            'lng' => 'sometimes|numeric|between:-180,180',
            'phone' => 'nullable|string|max:30',
            'operating_hours' => 'nullable|array',
            'holiday_dates' => 'nullable|array',
            'is_active' => 'sometimes|boolean',
        ]);

        $payload = collect($validated)->except(['lat', 'lng'])->all();

        if (isset($validated['lat'], $validated['lng'])) {
            $payload['location'] = $this->locationRaw($validated['lng'], $validated['lat']);
        }

        $branch->update($payload);

        return response()->json($this->formatBranch($this->freshBranch($branch->id)));
    }

    // ─── BRANCH LISTING (platform-wide, for Branch Operations) ─────

    public function branches(Request $request)
    {
        $validated = $request->validate([
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:100',
            'search' => 'nullable|string|max:100',
            'status' => 'nullable|string|in:active,inactive',
            'restaurant_id' => 'nullable|integer|exists:restaurants,id',
        ]);

        $limit = $validated['limit'] ?? 12;
        $page = $validated['page'] ?? 1;
        $search = trim((string) ($validated['search'] ?? ''));

        $branches = Branch::query()
            ->with('restaurant:id,name')
            ->select(self::BRANCH_SELECT)
            ->addSelect(DB::raw('ST_Y(location) as latitude'))
            ->addSelect(DB::raw('ST_X(location) as longitude'))
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($sq) use ($search) {
                    $sq->where('name', 'like', "%{$search}%")
                        ->orWhere('address', 'like', "%{$search}%")
                        ->orWhere('city', 'like', "%{$search}%")
                        ->orWhereHas('restaurant', function ($rq) use ($search) {
                            $rq->where('name', 'like', "%{$search}%");
                        });
                });
            })
            ->when(!empty($validated['status']), function ($query) use ($validated) {
                $query->where('is_active', $validated['status'] === 'active');
            })
            ->when(!empty($validated['restaurant_id']), function ($query) use ($validated) {
                $query->where('restaurant_id', $validated['restaurant_id']);
            })
            ->orderByDesc('id')
            ->paginate($limit, ['*'], 'page', $page);

        return response()->json([
            'stats' => [
                'total' => Branch::count(),
                'active' => Branch::where('is_active', true)->count(),
                'inactive' => Branch::where('is_active', false)->count(),
                'restaurants' => Branch::distinct()->count('restaurant_id'),
            ],
            'total' => $branches->total(),
            'page' => (int) $page,
            'limit' => (int) $limit,
            'data' => $branches->getCollection()
                ->map(fn ($branch) => $this->formatBranch($branch) + [
                    'key' => "branch-{$branch->id}",
                    'restaurant_name' => $branch->restaurant?->name,
                ])
                ->values(),
        ]);
    }

    public function deleteBranch(Restaurant $restaurant, Branch $branch)
    {
        abort_if($branch->restaurant_id !== $restaurant->id, 404);
        $branch->delete();
        return response()->json(['message' => 'Branch deleted']);
    }

    // ─── OPERATING HOURS ──────────────────────────────────────────

    public function getOperatingHours(Restaurant $restaurant)
    {
        return response()->json([
            'data' => $restaurant->branches()->get([
                'id', 'name', 'operating_hours', 'holiday_dates',
            ]),
        ]);
    }

    public function updateOperatingHours(Request $request, Restaurant $restaurant)
    {
        $validated = $request->validate([
            'branches' => 'required|array|min:1',
            'branches.*.id' => 'required|integer',
            'branches.*.operating_hours' => 'required|array',
            'branches.*.holiday_dates' => 'nullable|array',
        ]);

        foreach ($validated['branches'] as $input) {
            Branch::whereKey($input['id'])
                ->where('restaurant_id', $restaurant->id)
                ->update([
                    'operating_hours' => $input['operating_hours'],
                    'holiday_dates' => $input['holiday_dates'] ?? [],
                ]);
        }

        return response()->json(['message' => 'Operating hours updated']);
    }

    // ─── HELPERS ──────────────────────────────────────────────────

    /** Eager-load owner, branches (with coordinates) and counts. */
    private function loadForResponse(Restaurant $restaurant): Restaurant
    {
        return $restaurant->load([
            'owner:id,name,email',
            'branches' => function ($query) {
                $query->select(self::BRANCH_SELECT)
                    ->addSelect(DB::raw('ST_Y(location) as latitude'))
                    ->addSelect(DB::raw('ST_X(location) as longitude'));
            },
        ])->loadCount([
            'branches',
            'branches as active_branches_count' => function ($query) {
                $query->where('is_active', true);
            },
        ]);
    }

    /** Safe geometry expression — float-cast input prevents SQL injection. */
    private function locationRaw($lng, $lat)
    {
        return DB::raw(sprintf(
            "ST_GeomFromText('POINT(%F %F)')",
            (float) $lng,
            (float) $lat,
        ));
    }

    /** Re-fetch a branch including its resolved coordinates. */
    private function freshBranch(int $id)
    {
        return Branch::whereKey($id)
            ->select(self::BRANCH_SELECT)
            ->addSelect(DB::raw('ST_Y(location) as latitude'))
            ->addSelect(DB::raw('ST_X(location) as longitude'))
            ->firstOrFail();
    }

    private function formatRestaurant(Restaurant $restaurant): array
    {
        return [
            'key' => "restaurant-{$restaurant->id}",
            'id' => $restaurant->id,
            'name' => $restaurant->name,
            'slug' => $restaurant->slug,
            'description' => $restaurant->description,
            'cuisine_type' => $restaurant->cuisine_type,
            'category_id' => $restaurant->category_id,
            'status' => $restaurant->status,
            'logo_url' => $restaurant->logo ? asset('storage/'.$restaurant->logo) : null,
            'owner' => $restaurant->owner?->name,
            'owner_email' => $restaurant->owner?->email,
            'verified_at' => $restaurant->verified_at?->toISOString(),
            'created_at' => $restaurant->created_at?->toISOString(),
            'branches_count' => $restaurant->branches_count
                ?? $restaurant->branches->count(),
            'active_branches_count' => $restaurant->active_branches_count
                ?? $restaurant->branches->where('is_active', true)->count(),
            'branches' => $restaurant->branches
                ->map(fn ($branch) => $this->formatBranch($branch))
                ->values()
                ->all(),
        ];
    }

    private function formatBranch(Branch $branch): array
    {
        return [
            'id' => $branch->id,
            'restaurant_id' => $branch->restaurant_id,
            'name' => $branch->name,
            'address' => $branch->address,
            'city' => $branch->city,
            'postal_code' => $branch->postal_code,
            'phone' => $branch->phone,
            'is_active' => (bool) $branch->is_active,
            'is_main' => (bool) $branch->is_main,
            'latitude' => $branch->latitude !== null ? (float) $branch->latitude : null,
            'longitude' => $branch->longitude !== null ? (float) $branch->longitude : null,
            'operating_hours' => $branch->operating_hours ?? [],
            'holiday_dates' => $branch->holiday_dates ?? [],
        ];
    }

    /** Collision-safe slug (unique across soft-deleted rows too). */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'restaurant';
        $slug = $base;
        $suffix = 2;

        while (Restaurant::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix += 1;
        }

        return $slug;
    }

    private function storeLogo($file, $previous = null)
    {
        $this->deleteLogo($previous);

        return $file->store(self::LOGO_DIR, self::LOGO_DISK);
    }

    private function deleteLogo($path): void
    {
        if ($path) {
            Storage::disk(self::LOGO_DISK)->delete($path);
        }
    }
}
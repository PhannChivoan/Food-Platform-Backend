<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\CustomerAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => 'nullable|string',
            'status' => 'nullable|in:active,restricted',
            'trashed' => 'nullable|in:0,1',
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $page = $validated['page'] ?? 1;
        $limit = $validated['limit'] ?? 15;
        $search = trim((string) ($validated['search'] ?? ''));
        $status = $validated['status'] ?? null;
        $trashed = ($validated['trashed'] ?? '0') === '1';

        $query = User::role('customer');

        if ($trashed) {
            $query->onlyTrashed();
        }

        $query->withCount('orders')
            ->select(
                'users.*',
                DB::raw('(SELECT COALESCE(SUM(lt.points), 0) FROM loyalty_transactions lt WHERE lt.user_id = users.id AND lt.type = "earned") as points_earned'),
                DB::raw('(SELECT COALESCE(SUM(lt.points), 0) FROM loyalty_transactions lt WHERE lt.user_id = users.id AND lt.type = "burnt") as points_burnt'),
                DB::raw('(SELECT COALESCE(SUM(o.total), 0) FROM orders o WHERE o.customer_id = users.id) as total_spent'),
                DB::raw('(SELECT MAX(o.created_at) FROM orders o WHERE o.customer_id = users.id) as last_order_at'),
            );

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('users.name', 'like', "%{$search}%")
                    ->orWhere('users.email', 'like', "%{$search}%")
                    ->orWhere('users.phone', 'like', "%{$search}%");
            });
        }

        if ($status === 'active') {
            $query->where('users.is_active', true);
        } elseif ($status === 'restricted') {
            $query->where('users.is_active', false);
        }

        $customers = $query->orderByDesc('users.id')
            ->paginate($limit, ['*'], 'page', $page);

        $baseQuery = User::role('customer');
        if (!$trashed) {
            $baseQuery->withoutTrashed();
        }

        return response()->json([
            'stats' => [
                'total' => $baseQuery->count(),
                'active' => (clone $baseQuery)->where('is_active', true)->count(),
                'restricted' => (clone $baseQuery)->where('is_active', false)->count(),
                'verified' => (clone $baseQuery)->whereNotNull('email_verified_at')->count(),
                'new_this_month' => (clone $baseQuery)->where('created_at', '>=', now()->startOfMonth())->count(),
            ],
            'total' => $customers->total(),
            'page' => (int) $page,
            'limit' => (int) $limit,
            'data' => $customers->getCollection()->map(fn ($c) => [
                'key' => "customer-{$c->id}",
                'id' => $c->id,
                'name' => $c->name,
                'email' => $c->email,
                'phone' => $c->phone,
                'avatar' => $c->avatar_url,
                'is_active' => (bool) $c->is_active,
                'orders_count' => $c->orders_count ?? 0,
                'total_spent' => (float) ($c->total_spent ?? 0),
                'loyalty_balance' => ($c->points_earned ?? 0) - ($c->points_burnt ?? 0),
                'email_verified' => $c->email_verified_at !== null,
                'last_login_at' => $c->last_login_at?->toISOString(),
                'last_order_at' => $c->last_order_at,
                'created_at' => $c->created_at?->toISOString(),
                'deleted_at' => $c->deleted_at?->toISOString(),
            ])->values(),
        ]);
    }

    public function store(Request $request)
    {
        return response()->json(['message' => 'Use registration endpoint.'], 422);
    }

    public function show(User $customer)
    {
        if (!$customer->hasRole('customer')) {
            return response()->json(['message' => 'Not a customer'], 404);
        }

        $customer->loadCount('orders');
        $customer->load([
            'addresses',
            'loyaltyTransactions' => function ($q) {
                $q->with('order:id,restaurant_id,total')
                    ->with('restaurant:id,name')
                    ->orderByDesc('created_at')
                    ->limit(50);
            },
        ]);

        $pointsEarned = $customer->loyaltyTransactions->where('type', 'earned')->sum('points');
        $pointsBurnt = $customer->loyaltyTransactions->where('type', 'burnt')->sum('points');

        $totalSpent = $customer->orders()->sum('total');
        $lastOrder = $customer->orders()->orderByDesc('created_at')->value('created_at');

        return response()->json([
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'avatar' => $customer->avatar_url,
            'is_active' => (bool) $customer->is_active,
            'email_verified' => $customer->email_verified_at !== null,
            'last_login_at' => $customer->last_login_at?->toISOString(),
            'orders_count' => $customer->orders_count ?? 0,
            'total_spent' => (float) $totalSpent,
            'last_order_at' => $lastOrder?->toISOString(),
            'loyalty_balance' => $pointsEarned - $pointsBurnt,
            'loyalty_earned' => $pointsEarned,
            'loyalty_burnt' => $pointsBurnt,
            'addresses' => $customer->addresses
                ->sortByDesc('is_default')
                ->values()
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'label' => $a->label,
                    'address' => $a->address,
                    'city' => $a->city,
                    'postal_code' => $a->postal_code,
                    'is_default' => (bool) $a->is_default,
                ])->values(),
            'loyalty_transactions' => $customer->loyaltyTransactions->map(fn ($t) => [
                'id' => $t->id,
                'type' => $t->type,
                'points' => $t->points,
                'description' => $t->description,
                'order_id' => $t->order_id,
                'restaurant_name' => $t->restaurant?->name,
                'created_at' => $t->created_at?->toISOString(),
            ])->values(),
            'created_at' => $customer->created_at?->toISOString(),
        ]);
    }

    public function update(Request $request, User $customer)
    {
        if (!$customer->hasRole('customer')) abort(404);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|max:255|unique:users,email,' . $customer->id,
            'phone' => 'nullable|string|max:30',
            'is_active' => 'sometimes|boolean',
        ]);

        $customer->update($validated);

        $customer->loadCount('orders');
        $pointsEarned = $customer->loyaltyTransactions()->where('type', 'earned')->sum('points');
        $pointsBurnt = $customer->loyaltyTransactions()->where('type', 'burnt')->sum('points');
        $totalSpent = $customer->orders()->sum('total');
        $lastOrder = $customer->orders()->orderByDesc('created_at')->value('created_at');

        return response()->json([
            'key' => "customer-{$customer->id}",
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'avatar' => $customer->avatar_url,
            'is_active' => (bool) $customer->is_active,
            'orders_count' => $customer->orders_count ?? 0,
            'total_spent' => (float) $totalSpent,
            'loyalty_balance' => $pointsEarned - $pointsBurnt,
            'email_verified' => $customer->email_verified_at !== null,
            'last_login_at' => $customer->last_login_at?->toISOString(),
            'last_order_at' => $lastOrder?->toISOString(),
            'created_at' => $customer->created_at?->toISOString(),
        ]);
    }

    public function destroy(User $customer)
    {
        $customer->delete();
        return response()->json(['message' => 'Customer moved to trash.']);
    }

    public function restore(User $customer)
    {
        $customer->restore();
        return response()->json(['message' => 'Customer restored.', 'is_active' => (bool) $customer->is_active]);
    }

    public function forceDelete(User $customer)
    {
        $customer->forceDelete();
        return response()->json(['message' => 'Customer permanently deleted.']);
    }

    // ─── Addresses ───────────────────────────────────

    public function allAddresses(Request $request)
    {
        $validated = $request->validate([
            'search' => 'nullable|string',
            'city' => 'nullable|string',
            'default_only' => 'nullable|in:0,1',
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $page = $validated['page'] ?? 1;
        $limit = $validated['limit'] ?? 20;
        $search = trim((string) ($validated['search'] ?? ''));
        $city = trim((string) ($validated['city'] ?? ''));
        $defaultOnly = ($validated['default_only'] ?? '0') === '1';

        $query = CustomerAddress::with('user:id,name,email')
            ->select(
                'customer_addresses.*',
                DB::raw('(SELECT COUNT(*) FROM orders o WHERE o.delivery_address_id = customer_addresses.id) as orders_used'),
            );

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('address', 'like', "%{$search}%")
                    ->orWhere('label', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        if ($city !== '') {
            $query->where('city', 'like', "%{$city}%");
        }

        if ($defaultOnly) {
            $query->where('is_default', true);
        }

        $addresses = $query->orderByDesc('is_default')
            ->orderByDesc('id')
            ->paginate($limit, ['*'], 'page', $page);

        $allAddresses = CustomerAddress::all();
        $uniqueCities = $allAddresses->pluck('city')->filter()->unique()->values();

        return response()->json([
            'stats' => [
                'total' => CustomerAddress::count(),
                'default' => CustomerAddress::where('is_default', true)->count(),
                'cities' => $uniqueCities->count(),
                'new_this_week' => CustomerAddress::where('created_at', '>=', now()->subWeek())->count(),
            ],
            'cities' => $uniqueCities->values(),
            'total' => $addresses->total(),
            'page' => (int) $page,
            'limit' => (int) $limit,
            'data' => $addresses->getCollection()->map(fn ($a) => [
                'key' => "address-{$a->id}",
                'id' => $a->id,
                'user_id' => $a->user_id,
                'customer_name' => $a->user?->name,
                'customer_email' => $a->user?->email,
                'label' => $a->label,
                'address' => $a->address,
                'city' => $a->city,
                'postal_code' => $a->postal_code,
                'is_default' => (bool) $a->is_default,
                'orders_used' => $a->orders_used ?? 0,
                'created_at' => $a->created_at?->toISOString(),
            ])->values(),
        ]);
    }

    public function allAddressesShow(CustomerAddress $address)
    {
        $address->load('user:id,name,email');
        $address->loadCount('orders');

        return response()->json([
            'id' => $address->id,
            'user_id' => $address->user_id,
            'customer_name' => $address->user?->name,
            'customer_email' => $address->user?->email,
            'label' => $address->label,
            'address' => $address->address,
            'city' => $address->city,
            'postal_code' => $address->postal_code,
            'is_default' => (bool) $address->is_default,
            'orders_count' => $address->orders_count ?? 0,
            'created_at' => $address->created_at?->toISOString(),
        ]);
    }

    public function addresses(User $customer)
    {
        $addresses = $customer->addresses()->orderByDesc('is_default')->get();
        return response()->json($addresses->map(fn ($a) => [
            'id' => $a->id,
            'label' => $a->label,
            'address' => $a->address,
            'city' => $a->city,
            'postal_code' => $a->postal_code,
            'is_default' => (bool) $a->is_default,
        ])->values());
    }

    public function storeAddress(Request $request, User $customer)
    {
        $validated = $request->validate([
            'label' => 'nullable|string|max:50',
            'address' => 'required|string|max:500',
            'city' => 'required|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'is_default' => 'sometimes|boolean',
        ]);

        $lat = $validated['lat'];
        $lng = $validated['lng'];
        $location = DB::raw("ST_GeomFromText('POINT({$lng} {$lat})')");

        $validated['location'] = $location;
        unset($validated['lat'], $validated['lng']);

        if (!empty($validated['is_default'])) {
            $customer->addresses()->where('is_default', true)->update(['is_default' => false]);
        }

        $address = $customer->addresses()->create($validated);

        return response()->json([
            'id' => $address->id,
            'label' => $address->label,
            'address' => $address->address,
            'city' => $address->city,
            'postal_code' => $address->postal_code,
            'is_default' => (bool) $address->is_default,
        ], 201);
    }

    public function updateAddress(Request $request, User $customer, CustomerAddress $address)
    {
        abort_if($address->user_id !== $customer->id, 404);

        $validated = $request->validate([
            'label' => 'nullable|string|max:50',
            'address' => 'sometimes|string|max:500',
            'city' => 'sometimes|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'lat' => 'sometimes|numeric|between:-90,90',
            'lng' => 'sometimes|numeric|between:-180,180',
            'is_default' => 'sometimes|boolean',
        ]);

        if (isset($validated['lat']) && isset($validated['lng'])) {
            $lat = $validated['lat'];
            $lng = $validated['lng'];
            $validated['location'] = DB::raw("ST_GeomFromText('POINT({$lng} {$lat})')");
            unset($validated['lat'], $validated['lng']);
        }

        if (!empty($validated['is_default'])) {
            $customer->addresses()->where('is_default', true)->update(['is_default' => false]);
        }

        $address->update($validated);

        return response()->json([
            'id' => $address->id,
            'label' => $address->label,
            'address' => $address->address,
            'city' => $address->city,
            'postal_code' => $address->postal_code,
            'is_default' => (bool) $address->is_default,
        ]);
    }

    public function deleteAddress(User $customer, CustomerAddress $address)
    {
        abort_if($address->user_id !== $customer->id, 404);
        $address->delete();
        return response()->json(['message' => 'Address deleted.']);
    }

    public function setDefaultAddress(User $customer, CustomerAddress $address)
    {
        abort_if($address->user_id !== $customer->id, 404);

        $customer->addresses()->where('is_default', true)->update(['is_default' => false]);
        $address->update(['is_default' => true]);

        return response()->json(['message' => 'Default address updated.', 'is_default' => true]);
    }

    // ─── Blacklist ───────────────────────────────────

    public function blacklist(User $customer)
    {
        $customer->update(['is_active' => false]);
        return response()->json(['message' => 'Customer blacklisted.', 'is_active' => false]);
    }

    public function unblacklist(User $customer)
    {
        $customer->update(['is_active' => true]);
        return response()->json(['message' => 'Customer unblacklisted.', 'is_active' => true]);
    }

    // ─── Order History ───────────────────────────────

    public function orderHistory(User $customer, Request $request)
    {
        $page = $request->input('page', 1);
        $limit = $request->input('limit', 15);

        $orders = $customer->orders()
            ->with('restaurant:id,name')
            ->withCount('items')
            ->orderByDesc('created_at')
            ->paginate($limit, ['*'], 'page', $page);

        return response()->json([
            'total' => $orders->total(),
            'page' => (int) $page,
            'data' => $orders->getCollection()->map(fn ($o) => [
                'id' => $o->id,
                'status' => $o->status,
                'total' => (float) $o->total,
                'items_count' => $o->items_count,
                'restaurant_name' => $o->restaurant?->name,
                'created_at' => $o->created_at?->toISOString(),
            ])->values(),
        ]);
    }

    // ─── Loyalty ─────────────────────────────────────

    public function loyaltyBalance(User $customer)
    {
        $earned = $customer->loyaltyTransactions()->where('type', 'earned')->sum('points');
        $burnt = $customer->loyaltyTransactions()->where('type', 'burnt')->sum('points');

        $recentTransactions = $customer->loyaltyTransactions()
            ->with('restaurant:id,name')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'type' => $t->type,
                'points' => $t->points,
                'description' => $t->description,
                'restaurant_name' => $t->restaurant?->name,
                'created_at' => $t->created_at?->toISOString(),
            ]);

        return response()->json([
            'balance' => $earned - $burnt,
            'earned' => $earned,
            'burnt' => $burnt,
            'transactions' => $recentTransactions,
        ]);
    }

    // ─── Export ──────────────────────────────────────

    public function export(Request $request)
    {
        $status = $request->input('status');
        $search = trim((string) $request->input('search', ''));

        $query = User::role('customer')
            ->withoutTrashed()
            ->withCount('orders')
            ->select(
                'users.*',
                DB::raw('(SELECT COALESCE(SUM(lt.points), 0) FROM loyalty_transactions lt WHERE lt.user_id = users.id AND lt.type = "earned") as points_earned'),
                DB::raw('(SELECT COALESCE(SUM(lt.points), 0) FROM loyalty_transactions lt WHERE lt.user_id = users.id AND lt.type = "burnt") as points_burnt'),
                DB::raw('(SELECT COALESCE(SUM(o.total), 0) FROM orders o WHERE o.customer_id = users.id) as total_spent'),
            );

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('users.name', 'like', "%{$search}%")
                    ->orWhere('users.email', 'like', "%{$search}%")
                    ->orWhere('users.phone', 'like', "%{$search}%");
            });
        }

        if ($status === 'active') {
            $query->where('users.is_active', true);
        } elseif ($status === 'restricted') {
            $query->where('users.is_active', false);
        }

        $customers = $query->orderByDesc('users.id')->get();

        $csv = "ID,Name,Email,Phone,Active,Orders,Total Spent,Loyalty Balance,Registered\n";

        foreach ($customers as $c) {
            $loyalty = ($c->points_earned ?? 0) - ($c->points_burnt ?? 0);
            $csv .= implode(',', [
                $c->id,
                '"' . str_replace('"', '""', $c->name) . '"',
                $c->email,
                $c->phone ?? '',
                $c->is_active ? 'Yes' : 'No',
                $c->orders_count ?? 0,
                number_format($c->total_spent ?? 0, 2),
                $loyalty,
                $c->created_at?->format('Y-m-d'),
            ]) . "\n";
        }

        return Response::make($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="customers.csv"',
        ]);
    }
}

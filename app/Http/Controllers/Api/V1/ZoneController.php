<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeliveryZone;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ZoneController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => 'nullable|string',
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $page = $validated['page'] ?? 1;
        $limit = $validated['limit'] ?? 15;
        $search = trim((string) ($validated['search'] ?? ''));

        $query = DeliveryZone::withCount('branches');

        if ($search !== '') {
            $query->where('name', 'like', "%{$search}%");
        }

        $zones = $query->orderByDesc('id')
            ->paginate($limit, ['*'], 'page', $page);

        $allZones = DeliveryZone::all();

        return response()->json([
            'stats' => [
                'total' => DeliveryZone::count(),
                'total_branches' => Branch::count(),
                'avg_base_fee' => round($allZones->avg('base_fee') ?? 0, 2),
                'surge_rules' => DeliveryZone::whereNotNull('surge_pricing_rules')
                    ->where('surge_pricing_rules', '!=', '[]')
                    ->count(),
            ],
            'total' => $zones->total(),
            'page' => (int) $page,
            'limit' => (int) $limit,
            'data' => $zones->getCollection()->map(fn ($zone) => [
                'key' => "zone-{$zone->id}",
                'id' => $zone->id,
                'name' => $zone->name,
                'base_fee' => (float) $zone->base_fee,
                'fee_per_km' => (float) $zone->fee_per_km,
                'free_delivery_threshold' => $zone->free_delivery_threshold !== null
                    ? (float) $zone->free_delivery_threshold
                    : null,
                'surge_pricing_rules' => $zone->surge_pricing_rules ?? [],
                'branches_count' => $zone->branches_count,
                'created_at' => $zone->created_at?->toISOString(),
            ])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'base_fee' => 'required|numeric|min:0',
            'fee_per_km' => 'required|numeric|min:0',
            'free_delivery_threshold' => 'nullable|numeric|min:0',
            'surge_pricing_rules' => 'nullable|array',
            'branch_ids' => 'nullable|array',
            'branch_ids.*' => 'integer|exists:branches,id',
        ]);

        $zone = DeliveryZone::create([
            'name' => $validated['name'],
            'base_fee' => $validated['base_fee'],
            'fee_per_km' => $validated['fee_per_km'],
            'free_delivery_threshold' => $validated['free_delivery_threshold'] ?? null,
            'surge_pricing_rules' => $validated['surge_pricing_rules'] ?? [],
        ]);

        if (!empty($validated['branch_ids'])) {
            $zone->branches()->sync($validated['branch_ids']);
        }

        $zone->loadCount('branches');

        return response()->json([
            'key' => "zone-{$zone->id}",
            'id' => $zone->id,
            'name' => $zone->name,
            'base_fee' => (float) $zone->base_fee,
            'fee_per_km' => (float) $zone->fee_per_km,
            'free_delivery_threshold' => $zone->free_delivery_threshold !== null
                ? (float) $zone->free_delivery_threshold
                : null,
            'surge_pricing_rules' => $zone->surge_pricing_rules ?? [],
            'branches_count' => $zone->branches_count,
        ], 201);
    }

    public function show(DeliveryZone $zone)
    {
        $zone->loadCount('branches');
        $zone->load([
            'branches:id,restaurant_id,name,address,city,phone,is_active',
        ]);

        return response()->json([
            'key' => "zone-{$zone->id}",
            'id' => $zone->id,
            'name' => $zone->name,
            'base_fee' => (float) $zone->base_fee,
            'fee_per_km' => (float) $zone->fee_per_km,
            'free_delivery_threshold' => $zone->free_delivery_threshold !== null
                ? (float) $zone->free_delivery_threshold
                : null,
            'surge_pricing_rules' => $zone->surge_pricing_rules ?? [],
            'branches_count' => $zone->branches_count,
            'branches' => $zone->branches->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->name,
                'address' => $b->address,
                'city' => $b->city,
                'phone' => $b->phone,
                'is_active' => (bool) $b->is_active,
            ])->values(),
            'created_at' => $zone->created_at?->toISOString(),
        ]);
    }

    public function update(Request $request, DeliveryZone $zone)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'base_fee' => 'sometimes|numeric|min:0',
            'fee_per_km' => 'sometimes|numeric|min:0',
            'free_delivery_threshold' => 'nullable|numeric|min:0',
            'surge_pricing_rules' => 'nullable|array',
            'branch_ids' => 'nullable|array',
            'branch_ids.*' => 'integer|exists:branches,id',
        ]);

        $zone->update(collect($validated)->except('branch_ids')->all());

        if (array_key_exists('branch_ids', $validated)) {
            $zone->branches()->sync($validated['branch_ids']);
        }

        $zone->loadCount('branches');

        return response()->json([
            'key' => "zone-{$zone->id}",
            'id' => $zone->id,
            'name' => $zone->name,
            'base_fee' => (float) $zone->base_fee,
            'fee_per_km' => (float) $zone->fee_per_km,
            'free_delivery_threshold' => $zone->free_delivery_threshold !== null
                ? (float) $zone->free_delivery_threshold
                : null,
            'surge_pricing_rules' => $zone->surge_pricing_rules ?? [],
            'branches_count' => $zone->branches_count,
        ]);
    }

    public function destroy(DeliveryZone $zone)
    {
        $zone->delete();
        return response()->json(['message' => 'Zone deleted']);
    }

    public function branches(DeliveryZone $zone)
    {
        $zone->load('branches:id,restaurant_id,name,address,city,is_active');
        return response()->json($zone->branches);
    }

    public function assignBranch(Request $request, DeliveryZone $zone)
    {
        $request->validate([
            'branch_id' => 'required|integer|exists:branches,id',
        ]);

        $zone->branches()->syncWithoutDetaching([$request->branch_id]);

        return response()->json(['message' => 'Branch assigned to zone']);
    }

    public function removeBranch(DeliveryZone $zone, Branch $branch)
    {
        $zone->branches()->detach($branch->id);
        return response()->json(['message' => 'Branch removed from zone']);
    }

    public function getSurgeRules(DeliveryZone $zone)
    {
        return response()->json($zone->surge_pricing_rules ?? []);
    }

    public function updateSurgeRules(Request $request, DeliveryZone $zone)
    {
        $request->validate([
            'rules' => 'required|array',
            'rules.*.day' => 'required|string',
            'rules.*.start' => 'required|string',
            'rules.*.end' => 'required|string',
            'rules.*.multiplier' => 'required|numeric|min:1',
        ]);

        $zone->update(['surge_pricing_rules' => $request->rules]);
        return response()->json($zone);
    }

    public function calculateFee(Request $request)
    {
        $request->validate([
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
            'order_total' => 'nullable|numeric',
        ]);

        return response()->json(['delivery_fee' => 5.00]);
    }
}

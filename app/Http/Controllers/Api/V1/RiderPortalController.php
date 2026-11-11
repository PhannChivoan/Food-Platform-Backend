<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RiderProfile;
use App\Models\User;
use App\Services\DeliveryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** Self-service API for the rider web app: sign up, see my deliveries and earnings, finish a delivery with a photo. */
class RiderPortalController extends Controller
{
    public function register(Request $request)
    {
        $v = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'phone' => 'required|string|max:20',
            'password' => 'required|string|min:8|confirmed',
            'vehicle_type' => 'required|in:bike,scooter,car',
            'license_plate' => 'nullable|string|max:20',
        ]);

        $user = DB::transaction(function () use ($v) {
            $user = User::create([
                'name' => $v['name'],
                'email' => $v['email'],
                'password' => Hash::make($v['password']),
                'phone' => $v['phone'],
                'role' => 'rider',
                'is_active' => true,
            ]);
            $user->assignRole('rider');
            RiderProfile::create([
                'user_id' => $user->id,
                'vehicle_type' => $v['vehicle_type'],
                'license_plate' => $v['license_plate'] ?? null,
                'is_online' => true, // a new rider is visible to dispatch straight away
                'is_available' => true,
            ]);
            return $user;
        });

        return response()->json([
            'message' => 'Rider account created',
            'user' => $user->only(['id', 'name', 'email', 'role']),
            'token' => $user->createToken('rider_token')->plainTextToken,
        ], 201);
    }

    private function profile(Request $request): object
    {
        $p = DB::table('rider_profiles')->where('user_id', $request->user()->id)->whereNull('deleted_at')->first();
        abort_unless($p, 403, 'This account is not a rider.');
        return $p;
    }

    public function me(Request $request)
    {
        $p = $this->profile($request);
        $u = $request->user();
        $mine = DB::table('delivery_assignments')->where('rider_profile_id', $p->id);

        $today = (clone $mine)->where('status', 'delivered')->whereDate('delivered_at', today());
        return response()->json([
            'id' => $p->id,
            'name' => $u->name,
            'email' => $u->email,
            'phone' => $u->phone,
            'vehicle_type' => $p->vehicle_type,
            'license_plate' => $p->license_plate,
            'is_online' => (bool) $p->is_online,
            'stats' => [
                'to_deliver' => (clone $mine)->whereIn('status', ['assigned', 'picked_up'])->count(),
                'delivered_today' => (clone $today)->count(),
                'earnings_today' => round((float) (clone $today)->sum('earnings'), 2),
                'delivered_total' => (clone $mine)->where('status', 'delivered')->count(),
                'earnings_total' => round((float) (clone $mine)->where('status', 'delivered')->sum('earnings'), 2),
            ],
        ]);
    }

    public function toggleOnline(Request $request)
    {
        $p = $this->profile($request);
        // online = true/false sets it explicitly; without it the state is flipped
        $online = $request->has('online') ? $request->boolean('online') : !$p->is_online;
        DB::table('rider_profiles')->where('id', $p->id)->update(['is_online' => $online, 'updated_at' => now()]);
        return response()->json(['is_online' => $online]);
    }

    /** The rider's phone reports its GPS position so admins can follow the delivery. */
    public function location(Request $request)
    {
        $v = $request->validate(['lat' => 'required|numeric|between:-90,90', 'lng' => 'required|numeric|between:-180,180']);
        $p = $this->profile($request);
        $lat = (float) $v['lat'];
        $lng = (float) $v['lng'];
        DB::table('rider_profiles')->where('id', $p->id)->update([
            'current_location' => DB::raw("ST_GeomFromText('POINT({$lng} {$lat})')"),
            'updated_at' => now(),
        ]);
        return response()->json(['ok' => true]);
    }

    public function deliveries(Request $request)
    {
        $p = $this->profile($request);
        $scope = $request->query('scope') === 'completed' ? 'completed' : 'active';

        $rows = DB::table('delivery_assignments')->where('rider_profile_id', $p->id)
            ->when($scope === 'active',
                fn ($q) => $q->whereIn('status', ['assigned', 'picked_up'])->orderBy('assigned_at'),
                fn ($q) => $q->where('status', 'delivered')->orderByDesc('delivered_at')->limit(50))
            ->get();
        if ($rows->isEmpty()) return response()->json(['data' => []]);

        $orders = DB::table('orders')->whereIn('id', $rows->pluck('order_id'))->get()->keyBy('id');
        $restaurants = DB::table('restaurants')->whereIn('id', $orders->pluck('restaurant_id'))->get()->keyBy('id');
        $branches = DB::table('branches')->whereIn('id', $orders->pluck('branch_id'))
            ->selectRaw('id, name, address, city, phone, ST_Y(location) as lat, ST_X(location) as lng')->get()->keyBy('id');
        $customers = DB::table('users')->whereIn('id', $orders->pluck('customer_id'))->get()->keyBy('id');
        $addresses = DB::table('customer_addresses')->whereIn('id', $orders->pluck('delivery_address_id')->filter())
            ->selectRaw('id, label, address, details, city, postal_code, ST_Y(location) as lat, ST_X(location) as lng')->get()->keyBy('id');
        $items = DB::table('order_items')->whereIn('order_id', $orders->keys())->get()->groupBy('order_id');

        $data = $rows->map(function ($a) use ($orders, $restaurants, $branches, $customers, $addresses, $items) {
            $o = $orders->get($a->order_id);
            $meta = json_decode($o->meta ?? '[]', true) ?: [];
            $c = $customers->get($o->customer_id);
            $addr = $addresses->get($o->delivery_address_id);
            $b = $branches->get($o->branch_id);
            return [
                'id' => $a->id,
                'status' => $a->status,
                'assigned_at' => $a->assigned_at,
                'picked_up_at' => $a->picked_up_at,
                'delivered_at' => $a->delivered_at,
                'earnings' => (float) $a->earnings,
                'proof_photo_url' => $a->proof_photo ? \App\Services\CloudinaryService::url($a->proof_photo) : null,
                'order' => [
                    'number' => $o->order_number,
                    'total' => (float) $o->total,
                    'payment_method' => $o->payment_method,
                    'is_paid' => (bool) $o->is_paid,
                    'notes' => $o->customer_notes,
                    'items' => ($items->get($o->id) ?? collect())->map(fn ($i) => ['name' => $i->item_name, 'quantity' => (int) $i->quantity])->values(),
                ],
                'pickup' => [
                    'restaurant' => $restaurants->get($o->restaurant_id)->name ?? '',
                    'branch' => $b->name ?? '',
                    'address' => trim(($b->address ?? '') . ', ' . ($b->city ?? ''), ', '),
                    'phone' => $b->phone ?? null,
                    'lat' => isset($b->lat) ? (float) $b->lat : null,
                    'lng' => isset($b->lng) ? (float) $b->lng : null,
                ],
                'dropoff' => [
                    'customer' => $c->name ?? 'Customer',
                    'phone' => $meta['contact_phone'] ?? ($c->phone ?? null),
                    'label' => $addr->label ?? null,
                    'address' => $addr ? trim($addr->address . ', ' . $addr->city, ', ') : null,
                    'details' => $meta['unit_details'] ?? ($addr->details ?? null),
                    'lat' => $addr && isset($addr->lat) ? (float) $addr->lat : null,
                    'lng' => $addr && isset($addr->lng) ? (float) $addr->lng : null,
                ],
            ];
        })->values();

        return response()->json(['data' => $data]);
    }

    /** A driver can carry this many unfinished deliveries at once (accepting from the open list stops there). */
    private const MAX_ACTIVE = 3;

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** Orders the kitchen opened to riders and nobody has taken yet, nearest to the driver first. */
    public function availableOrders(Request $request)
    {
        $request->validate(['lat' => 'nullable|numeric|between:-90,90', 'lng' => 'nullable|numeric|between:-180,180']);
        $p = $this->profile($request);

        // the phone's live position when it sent one, otherwise the last one reported to dispatch
        $me = null;
        if ($request->filled('lat') && $request->filled('lng')) {
            $me = ['lat' => (float) $request->query('lat'), 'lng' => (float) $request->query('lng')];
        } else {
            $last = DB::table('rider_profiles')->where('id', $p->id)->selectRaw('ST_Y(current_location) as lat, ST_X(current_location) as lng')->first();
            if ($last && $last->lat !== null) $me = ['lat' => (float) $last->lat, 'lng' => (float) $last->lng];
        }

        $orders = DB::table('orders')
            ->where('open_to_riders', true)
            ->whereIn('status', ['accepted', 'preparing', 'ready'])
            ->where('payment_method', '!=', 'pickup')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('delivery_assignments')->whereColumn('delivery_assignments.order_id', 'orders.id'))
            ->orderBy('open_to_riders_at')
            ->limit(50)
            ->get();

        $active = DB::table('delivery_assignments')->where('rider_profile_id', $p->id)->whereIn('status', ['assigned', 'picked_up'])->count();
        if ($orders->isEmpty()) {
            return response()->json(['data' => [], 'meta' => ['active' => $active, 'max_active' => self::MAX_ACTIVE, 'online' => (bool) $p->is_online]]);
        }

        $restaurants = DB::table('restaurants')->whereIn('id', $orders->pluck('restaurant_id'))->get()->keyBy('id');
        $branches = DB::table('branches')->whereIn('id', $orders->pluck('branch_id'))
            ->selectRaw('id, name, address, city, phone, ST_Y(location) as lat, ST_X(location) as lng')->get()->keyBy('id');
        $customers = DB::table('users')->whereIn('id', $orders->pluck('customer_id'))->get()->keyBy('id');
        $addresses = DB::table('customer_addresses')->whereIn('id', $orders->pluck('delivery_address_id')->filter())
            ->selectRaw('id, label, address, details, city, ST_Y(location) as lat, ST_X(location) as lng')->get()->keyBy('id');
        $items = DB::table('order_items')->whereIn('order_id', $orders->pluck('id'))->get()->groupBy('order_id');

        $data = $orders->map(function ($o) use ($me, $restaurants, $branches, $customers, $addresses, $items) {
            $meta = json_decode($o->meta ?? '[]', true) ?: [];
            $b = $branches->get($o->branch_id);
            $addr = $addresses->get($o->delivery_address_id);
            $c = $customers->get($o->customer_id);
            $pickup = ['lat' => isset($b->lat) ? (float) $b->lat : null, 'lng' => isset($b->lng) ? (float) $b->lng : null];
            $drop = ['lat' => $addr && isset($addr->lat) ? (float) $addr->lat : null, 'lng' => $addr && isset($addr->lng) ? (float) $addr->lng : null];
            $has = fn ($pt) => $pt['lat'] !== null && $pt['lng'] !== null;

            return [
                'id' => $o->id,
                'status' => $o->status,
                'can_accept' => $o->status === 'ready',
                'opened_at' => $o->open_to_riders_at,
                // same rule as the fee credited on delivery: 10% of the food subtotal, at least $1.50
                'earnings' => max(1.5, round(((float) ($o->subtotal ?? 0)) * 0.10, 2)),
                'to_pickup_km' => $me && $has($pickup) ? round($this->haversineKm($me['lat'], $me['lng'], $pickup['lat'], $pickup['lng']), 1) : null,
                'trip_km' => $has($pickup) && $has($drop) ? round($this->haversineKm($pickup['lat'], $pickup['lng'], $drop['lat'], $drop['lng']), 1) : null,
                'order' => [
                    'number' => $o->order_number,
                    'total' => (float) $o->total,
                    'payment_method' => $o->payment_method,
                    'is_paid' => (bool) $o->is_paid,
                    'notes' => $o->customer_notes,
                    'items' => ($items->get($o->id) ?? collect())->map(fn ($i) => ['name' => $i->item_name, 'quantity' => (int) $i->quantity])->values(),
                ],
                'pickup' => [
                    'restaurant' => $restaurants->get($o->restaurant_id)->name ?? '',
                    'branch' => $b->name ?? '',
                    'address' => trim(($b->address ?? '') . ', ' . ($b->city ?? ''), ', '),
                    'phone' => $b->phone ?? null,
                ] + $pickup,
                'dropoff' => [
                    'customer' => $c->name ?? 'Customer',
                    'label' => $addr->label ?? null,
                    'address' => $addr ? trim($addr->address . ', ' . $addr->city, ', ') : null,
                    'details' => $meta['unit_details'] ?? ($addr->details ?? null),
                ] + $drop,
            ];
        })->sortBy(fn ($row) => [$row['can_accept'] ? 0 : 1, $row['to_pickup_km'] ?? 9999])->values();

        return response()->json(['data' => $data, 'meta' => ['active' => $active, 'max_active' => self::MAX_ACTIVE, 'online' => (bool) $p->is_online]]);
    }

    /** The driver takes an open order: it becomes theirs, exactly as if dispatch had assigned it. */
    public function acceptOrder(Request $request, int $id)
    {
        $p = $this->profile($request);
        abort_unless($p->is_online, 422, 'Go online to accept orders.');

        $assignmentId = DB::transaction(function () use ($p, $id) {
            // two drivers pressing Accept together: the row lock lets only the first one through
            $order = DB::table('orders')->where('id', $id)->lockForUpdate()->first();
            abort_unless($order && $order->open_to_riders && in_array($order->status, ['accepted', 'preparing', 'ready'], true), 422, 'This order is no longer open to riders.');
            abort_unless($order->status === 'ready', 422, 'The kitchen is still preparing this order. Accept it once it is ready.');
            abort_if($order->payment_method === 'pickup', 422, 'This is a pickup order.');
            abort_if(DB::table('delivery_assignments')->where('order_id', $order->id)->exists(), 422, 'Another driver already took this order.');

            $active = DB::table('delivery_assignments')->where('rider_profile_id', $p->id)->whereIn('status', ['assigned', 'picked_up'])->count();
            abort_if($active >= self::MAX_ACTIVE, 422, 'You already carry ' . self::MAX_ACTIVE . ' deliveries. Finish one first.');

            $assignment = DB::table('delivery_assignments')->insertGetId([
                'order_id' => $order->id,
                'rider_profile_id' => $p->id,
                'status' => 'assigned',
                'assigned_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('orders')->where('id', $order->id)->update(['status' => 'dispatched', 'dispatched_at' => now(), 'open_to_riders' => false, 'updated_at' => now()]);

            return $assignment;
        });

        return response()->json(['status' => 'accepted', 'delivery_id' => $assignmentId], 201);
    }

    private function myAssignment(Request $request, int $id): object
    {
        $p = $this->profile($request);
        $a = DB::table('delivery_assignments')->where('id', $id)->where('rider_profile_id', $p->id)->first();
        abort_unless($a, 404, 'Delivery not found');
        return $a;
    }

    public function pickup(Request $request, int $id)
    {
        $a = $this->myAssignment($request, $id);
        abort_unless($a->status === 'assigned', 422, 'This delivery was already picked up.');
        DeliveryService::pickUp($a->id);
        return response()->json(['status' => 'picked_up']);
    }

    /** Needs a photo of the hand-over; marks the delivery and the customer's order as delivered. */
    public function complete(Request $request, int $id)
    {
        $request->validate(['photo' => 'required|image|mimes:jpg,jpeg,png,webp|max:8192']);
        $a = $this->myAssignment($request, $id);
        abort_if($a->status === 'delivered', 422, 'This delivery is already completed.');
        abort_unless(in_array($a->status, ['assigned', 'picked_up'], true), 422, 'This delivery can no longer be completed.');

        $path = \App\Services\CloudinaryService::upload($request->file('photo'), 'delivery_proofs');
        DeliveryService::complete($a->id, $path);

        return response()->json(['status' => 'delivered', 'proof_photo_url' => \App\Services\CloudinaryService::url($path)]);
    }
}
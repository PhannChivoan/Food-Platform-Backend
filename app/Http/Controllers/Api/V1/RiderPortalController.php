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
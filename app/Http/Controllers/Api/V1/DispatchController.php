<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RiderProfile;
use App\Models\Order;
use App\Models\DeliveryAssignment;
use App\Models\User;
use App\Events\SkillsMP\DeliveryCompleted;
use App\Services\DeliveryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class DispatchController extends Controller
{
    private const DOCUMENT_DISK = 'public';
    private const DOCUMENT_DIR = 'riders/documents';

    public function dashboard()
    {
        $waitingOrders = Order::query()
            ->where('status', 'ready')
            ->whereDoesntHave('deliveryAssignment')
            ->with('restaurant:id,name')
            ->orderBy('ready_at')
            ->get();

        $activeAssignments = DeliveryAssignment::query()
            ->whereIn('status', ['assigned', 'picked_up'])
            ->with([
                'order:id,order_number',
                'rider.user:id,name',
            ])
            ->orderBy('assigned_at')
            ->get();

        $rows = $waitingOrders->map(fn ($order) => [
            'key' => "order-{$order->id}",
            'order' => $order->order_number ?: "#{$order->id}",
            'rider' => 'Unassigned',
            'distance' => '-',
            'status' => 'Pending',
        ])->concat($activeAssignments->map(fn ($assignment) => [
            'key' => "assignment-{$assignment->id}",
            'order' => $assignment->order?->order_number ?: "#{$assignment->order_id}",
            'rider' => $assignment->rider?->user?->name ?: 'Unknown',
            'distance' => '-',
            'status' => 'Active',
        ]))->values();

        $averageAssignmentMinutes = DeliveryAssignment::query()
            ->whereNotNull('assigned_at')
            ->whereNotNull('picked_up_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, assigned_at, picked_up_at)) as average_minutes')
            ->value('average_minutes');

        return response()->json([
            'stats' => [
                'waiting_for_rider' => $waitingOrders->count(),
                'available_riders' => RiderProfile::available()->count(),
                'active_trips' => $activeAssignments->count(),
                'average_assignment_minutes' => round((float) ($averageAssignmentMinutes ?? 0)),
            ],
            'data' => $rows,
        ]);
    }

    public function tracking(Request $request)
    {
        $validated = $request->validate([
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:100',
            'status' => 'nullable|string|in:assigned,picked_up,delivered,failed',
            'search' => 'nullable|string|max:100',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);

        $limit = $validated['limit'] ?? 15;

        $assignments = DeliveryAssignment::query()
            ->with([
                'order' => function ($query) {
                    $query->select('id', 'order_number', 'delivery_address_id', 'branch_id', 'status');
                },
                'order.branch' => function ($query) {
                    $query->select('id', 'name', 'restaurant_id')
                        ->addSelect(DB::raw('ST_Y(location) as branch_latitude'))
                        ->addSelect(DB::raw('ST_X(location) as branch_longitude'));
                },
                'order.deliveryAddress' => function ($query) {
                    $query->select('id', 'address', 'city', 'postal_code')
                        ->addSelect(DB::raw('ST_Y(location) as latitude'))
                        ->addSelect(DB::raw('ST_X(location) as longitude'));
                },
                'rider' => function ($query) {
                    $query->select('id', 'user_id', 'vehicle_type')
                        ->addSelect(DB::raw('ST_Y(current_location) as rider_latitude'))
                        ->addSelect(DB::raw('ST_X(current_location) as rider_longitude'));
                },
                'rider.user:id,name,phone',
            ])
            ->when(!empty($validated['status']), function ($query) use ($validated) {
                $query->where('status', $validated['status']);
            })
            ->when(!empty($validated['search']), function ($query) use ($validated) {
                $search = $validated['search'];
                $query->where(function ($q) use ($search) {
                    $q->whereHas('order', function ($oq) use ($search) {
                        $oq->where('order_number', 'like', "%{$search}%");
                    })->orWhereHas('rider.user', function ($uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%");
                    });
                });
            })
            ->when(!empty($validated['date_from']), function ($query) use ($validated) {
                $query->whereDate('assigned_at', '>=', $validated['date_from']);
            })
            ->when(!empty($validated['date_to']), function ($query) use ($validated) {
                $query->whereDate('assigned_at', '<=', $validated['date_to']);
            })
            ->orderByDesc('assigned_at')
            ->paginate($limit)
            ->withQueryString();

        // ─── Stats (platform-wide, independent of pagination) ───
        $deliveredToday = DeliveryAssignment::where('status', 'delivered')
            ->whereBetween('delivered_at', [now()->startOfDay(), now()->endOfDay()]);
        $deliveredTodayCount = (clone $deliveredToday)->count();
        $onTimeCount = (clone $deliveredToday)
            ->whereRaw('TIMESTAMPDIFF(MINUTE, assigned_at, delivered_at) <= 30')
            ->count();

        return response()->json([
            'total' => $assignments->total(),
            'page' => $assignments->currentPage(),
            'limit' => (int) $limit,
            'stats' => [
                'active_deliveries' => DeliveryAssignment::whereIn('status', ['assigned', 'picked_up'])->count(),
                'picked_up' => DeliveryAssignment::where('status', 'picked_up')->count(),
                'delayed' => DeliveryAssignment::where('status', 'assigned')
                    ->where('assigned_at', '<=', now()->subMinutes(30))
                    ->count(),
                'waiting_for_rider' => Order::where('status', 'ready')
                    ->whereDoesntHave('deliveryAssignment')
                    ->count(),
                'delivered_today' => $deliveredTodayCount,
                'on_time_rate' => $deliveredTodayCount > 0
                    ? round(($onTimeCount / $deliveredTodayCount) * 100)
                    : 0,
                'available_riders' => RiderProfile::available()->count(),
            ],
            'data' => $assignments->getCollection()->map(function ($assignment) {
                $address = $assignment->order?->deliveryAddress;
                $riderLat = $assignment->rider?->rider_latitude;
                $riderLng = $assignment->rider?->rider_longitude;
                $destLat = $address?->latitude;
                $destLng = $address?->longitude;

                $assignedMin = $assignment->assigned_at
                    ? (int) $assignment->assigned_at->diffInMinutes(now())
                    : null;

                $etaMinutes = null;
                if ($assignment->status === 'assigned' && $assignedMin !== null) {
                    $etaMinutes = max(0, 30 - $assignedMin);
                }

                if ($assignment->status === 'assigned' && $etaMinutes !== null) {
                    $eta = "{$etaMinutes} min";
                } elseif ($assignment->status === 'picked_up') {
                    $eta = 'Arriving';
                } else {
                    $eta = '-';
                }

                return [
                    'key' => "tracking-{$assignment->id}",
                    'assignment_id' => $assignment->id,
                    'order_id' => $assignment->order_id,
                    'rider_profile_id' => $assignment->rider_profile_id,
                    'order' => $assignment->order?->order_number ?: "#{$assignment->order_id}",
                    'branch_name' => $assignment->order?->branch?->name,
                    'restaurant_latitude' => $assignment->order?->branch?->branch_latitude !== null
                        ? (float) $assignment->order?->branch?->branch_latitude
                        : null,
                    'restaurant_longitude' => $assignment->order?->branch?->branch_longitude !== null
                        ? (float) $assignment->order?->branch?->branch_longitude
                        : null,
                    'destination' => $address
                        ? implode(', ', array_filter([
                            $address->address,
                            $address->city,
                            $address->postal_code,
                        ]))
                        : '-',
                    'eta' => $eta,
                    'eta_minutes' => $etaMinutes,
                    'assignment_status' => $assignment->status,
                'proof_photo_url' => $assignment->proof_photo ? asset('storage/' . $assignment->proof_photo) : null,
                    'rider' => $assignment->rider?->user?->name ?: 'Unknown',
                    'rider_phone' => $assignment->rider?->user?->phone,
                    'vehicle' => $assignment->rider?->vehicle_type,
                    'latitude' => $riderLat !== null ? (float) $riderLat : null,
                    'longitude' => $riderLng !== null ? (float) $riderLng : null,
                    'destination_latitude' => $destLat !== null ? (float) $destLat : null,
                    'destination_longitude' => $destLng !== null ? (float) $destLng : null,
                    'distance_km' => ($riderLat !== null && $destLat !== null)
                        ? round($this->haversineKm(
                            (float) $riderLat,
                            (float) $riderLng,
                            (float) $destLat,
                            (float) $destLng,
                        ), 1)
                        : null,
                    'route' => $assignment->route_points ?: [],
                    'assigned_at' => $assignment->assigned_at?->toISOString(),
                    'picked_up_at' => $assignment->picked_up_at?->toISOString(),
                    'delivered_at' => $assignment->delivered_at?->toISOString(),
                ];
            })->values(),
        ]);
    }

    public function destroyAssignment(DeliveryAssignment $assignment)
    {
        $order = $assignment->order;
        $assignment->delete();

        if ($order && $order->status === 'dispatched') {
            $order->update([
                'status' => 'ready',
                'dispatched_at' => null,
            ]);
        }

        return response()->json(['message' => 'Delivery assignment deleted']);
    }

    // Rider CRUD
    public function index(Request $request)
    {
        $limit = $request->input('limit', 15);
        $riders = RiderProfile::with('user:id,name,email,phone,avatar')
            ->selectRaw('*, ST_X(current_location) as longitude, ST_Y(current_location) as latitude')
            ->orderByDesc('id')
            ->paginate($limit);

        return response()->json(
            $riders->through(fn ($rider) => $this->formatRider($rider)),
        );
    }

    // Users that can still be attached as riders (no active rider profile).
    public function riderCandidates(Request $request)
    {
        $search = trim((string) $request->input('search', ''));

        $users = User::query()
            ->active()
            ->whereDoesntHave('riderProfile')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($sq) use ($search) {
                    $sq->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->limit((int) $request->input('limit', 100))
            ->get(['id', 'name', 'email', 'phone', 'avatar']);

        return response()->json([
            'data' => $users->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'avatar' => $this->avatarUrl($user->avatar),
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            // Ignore soft-deleted profiles so a removed rider can be re-added.
            'user_id' => [
                'required',
                'exists:users,id',
                Rule::unique('rider_profiles', 'user_id')->whereNull('deleted_at'),
            ],
            'vehicle_type' => 'required|string',
            'license_plate' => 'nullable|string',
            'avatar' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'documents' => 'nullable|array|max:5',
            'documents.*' => 'image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        $rider = RiderProfile::create([
            'user_id' => $validated['user_id'],
            'vehicle_type' => $validated['vehicle_type'],
            'license_plate' => $validated['license_plate'] ?? null,
            'documents' => $this->storeDocuments($request->file('documents', [])),
        ]);

        // Optional profile photo on creation (stored on the user record).
        if ($request->hasFile('avatar')) {
            $user = User::find($rider->user_id);

            if ($user) {
                $user->update([
                    'avatar' => $this->storeAvatar(
                        $request->file('avatar'),
                        $user->avatar,
                    ),
                ]);
            }
        }

        return response()->json($this->formatRider($rider->load('user')), 201);
    }

    public function show(RiderProfile $rider)
    {
        $rider->load('user')
            ->loadCount(['deliveryAssignments as delivered_trips' => function ($query) {
                $query->where('status', 'delivered');
            }])
            ->loadSum(['deliveryAssignments as delivered_earnings' => function ($query) {
                $query->where('status', 'delivered');
            }], 'earnings');

        $data = $this->formatRider($rider);
        $data['delivered_trips'] = (int) ($rider->delivered_trips ?? 0);
        $data['delivered_earnings'] = (float) ($rider->delivered_earnings ?? 0);
        $data['recent_deliveries'] = $rider->deliveryAssignments()
            ->where('status', 'delivered')
            ->with('order:id,order_number')
            ->orderByDesc('delivered_at')
            ->limit(5)
            ->get()
            ->map(fn ($assignment) => [
                'key' => "assignment-{$assignment->id}",
                'order_number' => $assignment->order?->order_number ?? "#{$assignment->order_id}",
                'delivered_at' => $assignment->delivered_at?->toISOString(),
                'earnings' => (float) $assignment->earnings,
            ])
            ->values()
            ->all();

        return response()->json($data);
    }

    public function update(Request $request, RiderProfile $rider)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => [
                'sometimes',
                'email',
                Rule::unique('users', 'email')->ignore($rider->user_id),
            ],
            'phone' => 'nullable|string|max:30',
            'vehicle_type' => 'sometimes|string',
            'license_plate' => 'nullable|string',
            'is_online' => 'sometimes|boolean',
            'is_available' => 'sometimes|boolean',
            'avatar' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'documents' => 'nullable|array|max:5',
            'documents.*' => 'image|mimes:jpg,jpeg,png,webp|max:2048',
            'remove_documents' => 'nullable|array',
            'remove_documents.*' => 'string',
        ]);

        $rider->update(collect($validated)->only([
            'vehicle_type', 'license_plate', 'is_online', 'is_available',
        ])->all());

        // Update the rider's user profile (name / email / phone / photo).
        $profileData = collect($validated)->only(['name', 'email', 'phone'])->all();

        if ($request->hasFile('avatar')) {
            $profileData['avatar'] = $this->storeAvatar(
                $request->file('avatar'),
                $rider->user?->avatar,
            );
        }

        if ($rider->user && !empty($profileData)) {
            $rider->user->update($profileData);
        }

        // Remove selected document images (from disk + JSON column).
        if (!empty($validated['remove_documents'])) {
            $remaining = collect($rider->documents ?? [])
                ->reject(fn ($path) => in_array($path, $validated['remove_documents'], true))
                ->values()
                ->all();
            $this->deleteDocumentFiles(collect($rider->documents ?? [])
                ->diff($remaining)
                ->values()
                ->all());
            $rider->update(['documents' => $remaining]);
        }

        // Append newly uploaded document images.
        if ($request->hasFile('documents')) {
            $current = $rider->documents ?? [];
            $incoming = $request->file('documents');

            if (count($current) + count($incoming) > 5) {
                return response()->json([
                    'message' => 'A rider can have at most 5 document images.',
                ], 422);
            }

            $rider->update([
                'documents' => array_merge($current, $this->storeDocuments($incoming)),
            ]);
        }

        return response()->json($this->formatRider($rider->refresh()->load('user')));
    }

    public function destroy(RiderProfile $rider)
    {
        $this->deleteDocumentFiles($rider->documents ?? []);
        $rider->delete();
        return response()->json(['message' => 'Rider profile deleted']);
    }

    // ─── Rider document image helpers ───
    private function formatRider(RiderProfile $rider): array
    {
        return [
            'id' => $rider->id,
            'user_id' => $rider->user_id,
            'user' => $rider->user ? [
                'id' => $rider->user->id,
                'name' => $rider->user->name,
                'email' => $rider->user->email,
                'phone' => $rider->user->phone,
                'avatar' => $this->avatarUrl($rider->user->avatar),
            ] : null,
            'vehicle_type' => $rider->vehicle_type,
            'license_plate' => $rider->license_plate,
            'rating' => (float) $rider->rating,
            'total_trips' => (int) $rider->total_trips,
            'total_earnings' => (float) $rider->total_earnings,
            'is_online' => (bool) $rider->is_online,
            'is_available' => (bool) $rider->is_available,
            'latitude' => $rider->latitude !== null ? (float) $rider->latitude : null,
            'longitude' => $rider->longitude !== null ? (float) $rider->longitude : null,
            'documents' => $this->documentUrls($rider->documents ?? []),
            'document_paths' => array_values($rider->documents ?? []),
        ];
    }

    private function documentUrls(array $paths): array
    {
        // Relative URLs – the UI joins them with the API origin.
        return collect($paths)
            ->map(fn ($path) => '/storage/'.ltrim($path, '/'))
            ->values()
            ->all();
    }

    private function storeDocuments(array $files): array
    {
        return collect($files)
            ->filter()
            ->map(fn ($file) => $file->store(self::DOCUMENT_DIR, self::DOCUMENT_DISK))
            ->values()
            ->all();
    }

    private function deleteDocumentFiles(array $paths): void
    {
        foreach ($paths as $path) {
            Storage::disk(self::DOCUMENT_DISK)->delete($path);
        }
    }

    private function avatarUrl($avatar)
    {
        if (! $avatar) {
            return null;
        }

        if (str_starts_with($avatar, 'http') || str_starts_with($avatar, '/storage')) {
            return $avatar;
        }

        return '/storage/'.ltrim($avatar, '/');
    }

    private function storeAvatar($file, $previous = null)
    {
        if ($previous && ! str_starts_with($previous, 'http') && ! str_starts_with($previous, '/storage')) {
            Storage::disk(self::DOCUMENT_DISK)->delete($previous);
        }

        return $file->store('riders/avatars', self::DOCUMENT_DISK);
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public function toggleOnline(RiderProfile $rider)
    {
        $rider->update(['is_online' => !$rider->is_online]);
        return response()->json(['is_online' => $rider->is_online]);
    }

    public function availableRiders()
    {
        $riders = RiderProfile::where('is_online', true)
            ->where('is_available', true)
            ->with('user:id,name,email,phone,avatar')
            ->get();
        return response()->json($riders);
    }

    // Assignment
    public function assignRider(Request $request, Order $order)
    {
        $validated = $request->validate(['rider_profile_id' => 'required|exists:rider_profiles,id']);
        $rider = RiderProfile::find($validated['rider_profile_id']);

        if ($order->deliveryAssignment) {
            return response()->json(['message' => 'Order already has a rider'], 422);
        }

        $assignment = DeliveryAssignment::create([
            'order_id' => $order->id,
            'rider_profile_id' => $rider->id,
            'status' => 'assigned',
            'assigned_at' => now(),
        ]);

        $order->update(['status' => 'dispatched', 'dispatched_at' => now()]);

        return response()->json([
            'message' => 'Rider assigned successfully',
            'id' => $assignment->id,
            'order_id' => $order->id,
            'rider_profile_id' => $rider->id,
        ], 201);
    }

    public function updateDeliveryStatus(Request $request, DeliveryAssignment $assignment)
    {
        $request->validate(['status' => 'required|in:assigned,picked_up,delivered,failed']);

        if ($request->status === 'picked_up' && $assignment->status === 'assigned') {
            DeliveryService::pickUp($assignment->id);
        } elseif ($request->status === 'delivered' && $assignment->status !== 'delivered') {
            DeliveryService::complete($assignment->id);
            if (class_exists(DeliveryCompleted::class)) {
                DeliveryCompleted::dispatch($assignment->fresh());
            }
        } else {
            $assignment->update(['status' => $request->status]);
        }
        return response()->json($assignment->fresh());
    }
    public function track(DeliveryAssignment $assignment)
    {
        // Real-time position: coordinates live in POINT geometry columns.
        $assignment->load([
            'rider' => function ($query) {
                $query->select('id', 'user_id', 'vehicle_type')
                    ->addSelect(DB::raw('ST_Y(current_location) as rider_latitude'))
                    ->addSelect(DB::raw('ST_X(current_location) as rider_longitude'));
            },
            'order.deliveryAddress' => function ($query) {
                $query->select('id', 'address', 'city', 'postal_code')
                    ->addSelect(DB::raw('ST_Y(location) as latitude'))
                    ->addSelect(DB::raw('ST_X(location) as longitude'));
            },
        ]);

        $riderLat = $assignment->rider?->rider_latitude;
        $riderLng = $assignment->rider?->rider_longitude;
        $address = $assignment->order?->deliveryAddress;
        $destLat = $address?->latitude;
        $destLng = $address?->longitude;

        return response()->json([
            'status' => $assignment->status,
            'route' => $assignment->route_points ?? [],
            'latitude' => $riderLat !== null ? (float) $riderLat : null,
            'longitude' => $riderLng !== null ? (float) $riderLng : null,
            'destination_latitude' => $destLat !== null ? (float) $destLat : null,
            'destination_longitude' => $destLng !== null ? (float) $destLng : null,
            'distance_km' => ($riderLat !== null && $destLat !== null)
                ? round($this->haversineKm(
                    (float) $riderLat,
                    (float) $riderLng,
                    (float) $destLat,
                    (float) $destLng,
                ), 1)
                : null,
        ]);
    }

    public function earnings(RiderProfile $rider)
    {
        $total = $rider->deliveryAssignments()->where('status', 'delivered')->sum('earnings');
        return response()->json(['total_earnings' => $total]);
    }

    public function earningsDashboard(Request $request)
    {
        $validated = $request->validate([
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:100',
            'search' => 'nullable|string|max:100',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);

        $limit = $validated['limit'] ?? 15;
        $page = $validated['page'] ?? 1;

        // Delivered-trip scope shared by the per-rider aggregates and the stats.
        $deliveredScope = function ($query) use ($validated) {
            $query->where('status', 'delivered');

            if (!empty($validated['date_from'])) {
                $query->whereDate('delivered_at', '>=', $validated['date_from']);
            }

            if (!empty($validated['date_to'])) {
                $query->whereDate('delivered_at', '<=', $validated['date_to']);
            }
        };

        $riders = RiderProfile::query()
            ->with('user:id,name,email,avatar')
            ->when(!empty($validated['search']), function ($query) use ($validated) {
                $search = $validated['search'];
                $query->where(function ($q) use ($search) {
                    $q->whereHas('user', function ($uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    })->orWhere('vehicle_type', 'like', "%{$search}%")
                        ->orWhere('license_plate', 'like', "%{$search}%");
                });
            })
            ->withCount(['deliveryAssignments as delivered_trips' => $deliveredScope])
            ->withSum(['deliveryAssignments as delivered_earnings' => $deliveredScope], 'earnings')
            ->withMax(['deliveryAssignments as last_delivery_at' => $deliveredScope], 'delivered_at')
            ->orderByDesc('delivered_earnings')
            ->paginate($limit)
            ->withQueryString();

        // ─── Stats ───
        $deliveredToday = DeliveryAssignment::query()
            ->where('status', 'delivered')
            ->whereBetween('delivered_at', [now()->startOfDay(), now()->endOfDay()]);

        $tripsToday = (clone $deliveredToday)->count();
        $earningsToday = (float) (clone $deliveredToday)->sum('earnings');

        $rangeTrips = DeliveryAssignment::query()
            ->where('status', 'delivered')
            ->when(!empty($validated['date_from']), function ($query) use ($validated) {
                $query->whereDate('delivered_at', '>=', $validated['date_from']);
            })
            ->when(!empty($validated['date_to']), function ($query) use ($validated) {
                $query->whereDate('delivered_at', '<=', $validated['date_to']);
            });

        $grossEarnings = (float) (clone $rangeTrips)->sum('earnings');
        $grossTripCount = (clone $rangeTrips)->count();

        return response()->json([
            'stats' => [
                'trips_today' => $tripsToday,
                'earnings_today' => round($earningsToday, 2),
                'gross_earnings' => round($grossEarnings, 2),
                'average_per_trip' => $grossTripCount > 0
                    ? round($grossEarnings / $grossTripCount, 2)
                    : 0,
                'available_riders' => RiderProfile::available()->count(),
            ],
            'total' => $riders->total(),
            'page' => (int) $page,
            'limit' => (int) $limit,
            'data' => $riders->map(function ($rider) {
                $earnings = (float) ($rider->delivered_earnings ?? 0);
                $trips = (int) ($rider->delivered_trips ?? 0);

                return [
                    'key' => "rider-{$rider->id}",
                    'rider_profile_id' => $rider->id,
                    'rider' => $rider->user?->name ?: $rider->user?->email ?: "Rider #{$rider->id}",
                    'email' => $rider->user?->email,
                    'avatar' => $rider->user?->avatar,
                    'vehicle_type' => $rider->vehicle_type,
                    'license_plate' => $rider->license_plate,
                    'trips' => $trips,
                    'earnings' => round($earnings, 2),
                    'average_per_trip' => $trips > 0 ? round($earnings / $trips, 2) : 0,
                    'last_delivery_at' => $rider->last_delivery_at,
                    'rating' => (float) $rider->rating,
                    'is_online' => (bool) $rider->is_online,
                    'is_available' => (bool) $rider->is_available,
                    'latitude' => $rider->latitude !== null ? (float) $rider->latitude : null,
                    'longitude' => $rider->longitude !== null ? (float) $rider->longitude : null,
                ];
            })->values(),
        ]);
    }

    public function performance(RiderProfile $rider)
    {
        $totalTrips = $rider->deliveryAssignments()->count();
        $onTime = $rider->deliveryAssignments()
                        ->where('status', 'delivered')
                        ->whereNotNull('delivered_at')
                        // you can add logic to check if delivered within SLA
                        ->count();
        return response()->json([
            'total_trips' => $totalTrips,
            'on_time_deliveries' => $onTime,
            'rating' => $rider->rating,
        ]);
    }

    public function batchDispatch(Request $request)
    {
        $validated = $request->validate([
            'order_ids' => 'required|array|exists:orders,id',
            'rider_profile_id' => 'required|exists:rider_profiles,id',
        ]);

        $rider = RiderProfile::find($validated['rider_profile_id']);
        $assigned = 0;
        $skipped = 0;

        foreach ($validated['order_ids'] as $orderId) {
            $order = Order::find($orderId);
            if (!$order || $order->status !== 'ready' || $order->deliveryAssignment) {
                $skipped++;
                continue;
            }

            DeliveryAssignment::create([
                'order_id' => $order->id,
                'rider_profile_id' => $rider->id,
                'status' => 'assigned',
                'assigned_at' => now(),
            ]);

            $order->update(['status' => 'dispatched', 'dispatched_at' => now()]);
            $assigned++;
        }

        return response()->json([
            'message' => "{$assigned} order(s) dispatched" . ($skipped > 0 ? ", {$skipped} skipped" : ""),
            'assigned' => $assigned,
            'skipped' => $skipped,
        ]);
    }
}
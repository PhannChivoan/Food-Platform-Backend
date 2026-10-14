<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\MenuItem;
use App\Models\RiderProfile;
use App\Events\SkillsMP\OrderCompleted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => 'nullable|string|in:pending,accepted,preparing,ready,dispatched,delivered,cancelled,rejected',
            'search' => 'nullable|string|max:100',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'min_rating' => 'nullable|numeric|min:0|max:5',
            'payment_method' => 'nullable|string|in:cash,card,wallet,bank_transfer',
            'is_paid' => 'nullable|string|in:true,false,0,1',
            'restaurant_id' => 'nullable|integer|exists:restaurants,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $query = Order::query()
            ->select([
                'id',
                'order_number',
                'customer_id',
                'restaurant_id',
                'branch_id',
                'status',
                'subtotal',
                'delivery_fee',
                'surge_fee',
                'discount',
                'tax',
                'total',
                'payment_method',
                'is_paid',
                'delivery_address_id',
                'customer_notes',
                'accepted_at',
                'preparing_at',
                'ready_at',
                'dispatched_at',
                'delivered_at',
                'cancelled_at',
                'created_at',
                'updated_at',
            ])
            ->with([
                'customer:id,name,email',
                'restaurant:id,name',
                'branch:id,name',
                'items:id,order_id,menu_item_id,item_name,unit_price,quantity,subtotal',
                'items.menuItem:id,name,image',
            ])
            ->withAvg(['comments as average_rating' => function ($query) {
                $query->where('is_approved', true);
            }], 'rating');
        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (!empty($validated['restaurant_id'])) {
            $query->where('restaurant_id', $validated['restaurant_id']);
        }
        if (!empty($validated['branch_id'])) {
            $query->where('branch_id', $validated['branch_id']);
        }
        if (isset($validated['is_paid'])) {
            $query->where('is_paid', in_array($validated['is_paid'], ['true', '1', 1], true) ? 1 : 0);
        }
        if (!empty($validated['payment_method'])) {
            $query->where('payment_method', $validated['payment_method']);
        }
        if (!empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }
        if (!empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }
        if (!empty($validated['min_rating'])) {
            $query->whereHas('comments', function ($commentQuery) use ($validated) {
                $commentQuery->where('is_approved', true)
                    ->where('rating', '>=', $validated['min_rating']);
            });
        }
        if (!empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($searchQuery) use ($search) {
                $searchQuery->where('order_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($customerQuery) use ($search) {
                        $customerQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    })
                    ->orWhereHas('restaurant', function ($restaurantQuery) use ($search) {
                        $restaurantQuery->where('name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('branch', function ($branchQuery) use ($search) {
                        $branchQuery->where('name', 'like', "%{$search}%");
                    });
            });
        }

        $limit = $validated['limit'] ?? 15;

        return response()->json(
            $query->orderBy('created_at', 'desc')->paginate($limit)
        );
    }

    public function completed(Request $request)
    {
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:100',
            'search' => 'nullable|string|max:100',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'restaurant_id' => 'nullable|integer|exists:restaurants,id',
            'rider_id' => 'nullable|integer|exists:rider_profiles,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'payment_method' => 'nullable|string|in:cash,card,wallet,bank_transfer',
            'is_paid' => 'nullable|string|in:true,false,0,1',
            'sort_by' => 'nullable|string|in:delivered_asc,delivered_desc,total_asc,total_desc',
        ]);

        $query = Order::query()
            ->select([
                'id',
                'order_number',
                'customer_id',
                'restaurant_id',
                'subtotal',
                'delivery_fee',
                'surge_fee',
                'discount',
                'tax',
                'total',
                'payment_method',
                'is_paid',
                'customer_notes',
                'accepted_at',
                'preparing_at',
                'ready_at',
                'dispatched_at',
                'delivered_at',
                'created_at',
            ])
            ->where('status', 'delivered')
            ->with([
                'restaurant:id,name',
                'customer:id,name,phone,email',
                'deliveryAssignment.rider.user:id,name,phone',
                'deliveryAssignment.rider:id,user_id,vehicle_type,license_plate,rating,total_trips,total_earnings',
            ]);

        // ─── FILTERS ───
        if (!empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($cq) use ($search) {
                        $cq->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    })
                    ->orWhereHas('restaurant', function ($rq) use ($search) {
                        $rq->where('name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('deliveryAssignment.rider.user', function ($ruq) use ($search) {
                        $ruq->where('name', 'like', "%{$search}%");
                    });
            });
        }

        if (!empty($validated['date_from'])) {
            $query->whereDate('delivered_at', '>=', $validated['date_from']);
        }

        if (!empty($validated['date_to'])) {
            $query->whereDate('delivered_at', '<=', $validated['date_to']);
        }

        if (!empty($validated['restaurant_id'])) {
            $query->where('restaurant_id', $validated['restaurant_id']);
        }

        if (!empty($validated['branch_id'])) {
            $query->where('branch_id', $validated['branch_id']);
        }

        if (!empty($validated['rider_id'])) {
            $query->whereHas('deliveryAssignment', function ($dq) use ($validated) {
                $dq->where('rider_profile_id', $validated['rider_id']);
            });
        }

        if (!empty($validated['payment_method'])) {
            $query->where('payment_method', $validated['payment_method']);
        }

        if (isset($validated['is_paid'])) {
            $query->where('is_paid', in_array($validated['is_paid'], ['true', '1', 1], true) ? 1 : 0);
        }

        // ─── SORT ───
        switch ($validated['sort_by'] ?? 'delivered_desc') {
            case 'delivered_asc':
                $query->orderBy('delivered_at', 'asc');
                break;
            case 'total_asc':
                $query->orderBy('total', 'asc');
                break;
            case 'total_desc':
                $query->orderBy('total', 'desc');
                break;
            default:
                $query->orderBy('delivered_at', 'desc');
                break;
        }

        $limit = $validated['limit'] ?? 15;
        $orders = $query->paginate($limit);

        // ─── STATS (always on unfiltered delivered orders) ───
        $completedOrders = Order::where('status', 'delivered');
        $deliveredCount = (clone $completedOrders)->count();
        $onTimeCount = (clone $completedOrders)
            ->whereNotNull('accepted_at')
            ->whereNotNull('delivered_at')
            ->whereRaw('TIMESTAMPDIFF(MINUTE, accepted_at, delivered_at) <= 30')
            ->count();
        $rating = \App\Models\Comment::query()
            ->where('commentable_type', Order::class)
            ->where('is_approved', true)
            ->whereIn('commentable_id', (clone $completedOrders)->select('id'))
            ->avg('rating');

        $payload = $orders->toArray();
        $payload['stats'] = [
            'completed_today' => (clone $completedOrders)
                ->whereBetween('delivered_at', [now()->startOfDay(), now()->endOfDay()])
                ->count(),
            'completed_this_week' => (clone $completedOrders)
                ->whereBetween('delivered_at', [now()->startOfWeek(), now()->endOfWeek()])
                ->count(),
            'on_time_delivery' => $deliveredCount > 0
                ? round(($onTimeCount / $deliveredCount) * 100)
                : 0,
            'customer_rating' => round((float) ($rating ?? 0), 2),
        ];

        return response()->json($payload);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'restaurant_id' => 'required|exists:restaurants,id',
            'branch_id' => 'required|exists:branches,id',
            'delivery_address_id' => 'nullable|exists:customer_addresses,id',
            'items' => 'required|array',
            'items.*.menu_item_id' => 'required|exists:menu_items,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.selected_modifiers' => 'nullable|array',
            'items.*.selected_modifiers.*' => 'integer|exists:modifiers,id',
            'payment_method' => 'required|string',
            'customer_notes' => 'nullable|string',
        ]);

        $subtotal = 0;
        $createdItems = [];
        $orderItems = [];

        DB::beginTransaction();
        try {
            $order = Order::create([
                'order_number' => 'ORD-' . now()->format('Ymd') . '-' . Str::random(6),
                'customer_id' => auth()->id(),
                'restaurant_id' => $validated['restaurant_id'],
                'branch_id' => $validated['branch_id'],
                'delivery_address_id' => $validated['delivery_address_id'] ?? null,
                'status' => 'pending',
                'subtotal' => 0,
                'delivery_fee' => 0, // calculate later
                'surge_fee' => 0,
                'discount' => 0,
                'tax' => 0,
                'total' => 0,
                'payment_method' => $validated['payment_method'],
                'is_paid' => false,
                'customer_notes' => $validated['customer_notes'] ?? null,
                'meta' => [],
            ]);

            // Batch-load menu items to avoid N+1
            $menuItemIds = array_column($validated['items'], 'menu_item_id');
            // Customers are outside every tenant scope, so look items up unscoped (restricted to this restaurant).
            $menuItems = MenuItem::withoutGlobalScopes()
                ->where('restaurant_id', $validated['restaurant_id'])
                ->where('is_available', true)
                ->whereIn('id', $menuItemIds)->get()->keyBy('id');

            foreach ($validated['items'] as $itemData) {
                $menuItem = $menuItems->get($itemData['menu_item_id']);
                if (!$menuItem) continue;
                $unitPrice = (float) ($menuItem->discounted_price ?? $menuItem->price);
                $chosen = [];
                $modIds = $itemData['selected_modifiers'] ?? [];
                if ($modIds) {
                    $allowed = DB::table('modifiers')
                        ->join('menu_item_modifier_groups', 'menu_item_modifier_groups.modifier_group_id', '=', 'modifiers.modifier_group_id')
                        ->where('menu_item_modifier_groups.menu_item_id', $menuItem->id)
                        ->whereIn('modifiers.id', $modIds)
                        ->select('modifiers.id', 'modifiers.name', 'modifiers.price_adjustment')->get();
                    foreach ($allowed as $m) {
                        $unitPrice += (float) $m->price_adjustment;
                        $chosen[] = ['id' => $m->id, 'name' => $m->name, 'price_adjustment' => (float) $m->price_adjustment];
                    }
                }
                $subtotal += $unitPrice * $itemData['quantity'];
                $orderItem = OrderItem::create([
                    'order_id' => $order->id,
                    'menu_item_id' => $menuItem->id,
                    'item_name' => $menuItem->name,
                    'unit_price' => $unitPrice,
                    'quantity' => $itemData['quantity'],
                    'selected_modifiers' => $chosen,
                    'subtotal' => $unitPrice * $itemData['quantity'],
                ]);
                $createdItems[] = $orderItem;
            }

            // Update order totals
            $order->subtotal = $subtotal;
            $order->total = $subtotal; // you can add fees later
            $order->save();

            DB::commit();

            return response()->json(array_merge($order->toArray(), ['items' => $createdItems]), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function show(Order $order)
    {
        return response()->json($order->load('customer', 'restaurant', 'items.menuItem', 'deliveryAssignment.rider.user'));
    }

    public function update(Request $request, Order $order)
    {
        if (!in_array($order->status, ['pending', 'accepted'], true)) {
            return response()->json(['message' => 'This order can no longer be edited.'], 422);
        }

        $validated = $request->validate([
            'restaurant_id' => 'sometimes|integer|exists:restaurants,id',
            'branch_id' => 'sometimes|integer|exists:branches,id',
            'delivery_address_id' => 'nullable|integer|exists:customer_addresses,id',
            'payment_method' => 'sometimes|string|in:cash,card,wallet,bank_transfer',
            'customer_notes' => 'nullable|string|max:500',
            'is_paid' => 'sometimes|boolean',
        ]);

        $order->update($validated);
        return response()->json($order->fresh(['customer', 'restaurant', 'branch']));
    }

    public function destroy(Order $order)
    {
        if (!in_array($order->status, ['pending', 'rejected', 'cancelled'], true)) {
            return response()->json(['message' => 'This order cannot be deleted.'], 422);
        }

        $order->delete();
        return response()->json(['message' => 'Order deleted']);
    }

    // ---------- Kitchen / Status Endpoints ----------

    public function queue(Request $request)
    {
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:100',
            'status' => 'nullable|string|in:pending,accepted,preparing,ready',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'search' => 'nullable|string|max:100',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);

        $limit = $validated['limit'] ?? 15;
        $orders = Order::whereIn('status', ['pending', 'accepted', 'preparing', 'ready'])
                       // online-paid orders reach the kitchen only after PayWay confirms the payment
                       ->where(function ($q) {
                           $q->whereNotIn('payment_method', ['aba_khqr', 'card'])->orWhere('is_paid', true);
                       })
                       ->with(['restaurant', 'branch', 'items'])
                       ->when($validated['status'] ?? null, function ($query, $status) {
                           $query->where('status', $status);
                       })
                       ->when($validated['branch_id'] ?? null, fn ($query, $branch) => $query->where('branch_id', $branch))
                       ->when($validated['search'] ?? null, function ($query, $search) {
                           $query->where(function ($searchQuery) use ($search) {
                               $searchQuery->where('order_number', 'like', "%{$search}%")
                                   ->orWhereHas('restaurant', function ($restaurantQuery) use ($search) {
                                       $restaurantQuery->where('name', 'like', "%{$search}%");
                                   });
                           });
                       })
                       ->when($validated['date_from'] ?? null, function ($query, $dateFrom) {
                           $query->whereDate('created_at', '>=', $dateFrom);
                       })
                       ->when($validated['date_to'] ?? null, function ($query, $dateTo) {
                           $query->whereDate('created_at', '<=', $dateTo);
                       })
                       ->orderBy('created_at')
                       ->paginate($limit)
                       ->withQueryString();

        return response()->json($orders);
    }

    public function accept(Order $order)
    {
        abort_unless($order->status === 'pending', 422, 'Only pending orders can be accepted.');
        abort_if(in_array($order->payment_method, ['aba_khqr', 'card'], true) && !$order->is_paid, 422, 'Waiting for the online payment of this order.');
        $order->update(['status' => 'accepted', 'accepted_at' => now()]);
        return response()->json($order);
    }

    public function reject(Request $request, Order $order)
    {
        $request->validate(['reason' => 'required|string']);
        abort_unless(in_array($order->status, ['pending', 'accepted'], true), 422, 'This order can no longer be rejected.');
        $order->update(['status' => 'rejected', 'meta->reject_reason' => $request->reason]);
        return response()->json($order);
    }

    public function updateStatus(Request $request, Order $order)
    {
        $request->validate(['status' => 'required|in:pending,accepted,preparing,ready,dispatched,delivered,cancelled']);
        $oldStatus = $order->status;
        $order->update(['status' => $request->status]);

        if ($oldStatus !== 'delivered' && $request->status === 'delivered') {
            $order->update(['delivered_at' => now()]);
            if (class_exists(OrderCompleted::class)) {
                OrderCompleted::dispatch($order->fresh());
            }
        }

        return response()->json($order);
    }

    public function startPreparing(Order $order)
    {
        $order->update(['status' => 'preparing', 'preparing_at' => now()]);
        return response()->json($order);
    }

    public function markReady(Order $order)
    {
        $order->update(['status' => 'ready', 'ready_at' => now()]);
        return response()->json($order);
    }

    public function cancel(Order $order)
    {
        $order->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        return response()->json($order);
    }

    public function timeline(Order $order)
    {
        return response()->json([
            'created_at' => $order->created_at,
            'accepted_at' => $order->accepted_at,
            'preparing_at' => $order->preparing_at,
            'ready_at' => $order->ready_at,
            'dispatched_at' => $order->dispatched_at,
            'delivered_at' => $order->delivered_at,
            'cancelled_at' => $order->cancelled_at,
        ]);
    }

    public function kitchenMetrics(Request $request)
    {
        $branch = $request->integer('branch_id') ?: null;
        $orders = fn () => Order::query()->when($branch, fn ($q) => $q->where('branch_id', $branch));

        $pending = $orders()->where('status', 'pending')->count();
        $inProgress = $orders()->whereIn('status', ['accepted', 'preparing'])->count();
        $ready = $orders()->where('status', 'ready')->count();
        $avgPrep = $orders()->whereNotNull('preparing_at')
                        ->whereNotNull('ready_at')
                        ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, preparing_at, ready_at)) as avg')
                        ->value('avg');
        return response()->json([
            'pending' => $pending,
            'in_progress' => $inProgress,
            'ready' => $ready,
            'average_prep_time_minutes' => round($avgPrep ?? 0),
        ]);
    }}
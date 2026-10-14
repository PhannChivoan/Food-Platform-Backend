<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** A customer's own orders: history, live tracking, and an order-update notification feed. */
class MyOrderController extends Controller
{
    private const ONLINE = ['aba_khqr', 'card'];
    private const DONE = ['delivered', 'cancelled', 'rejected'];

    /** Orders that really exist for the customer (not an online payment that was abandoned). */
    private function base(int $userId)
    {
        return DB::table('orders')->where('customer_id', $userId)
            ->where(function ($q) {
                $q->whereNull('meta->payment_status')->orWhere('meta->payment_status', '!=', 'cancelled');
            })
            ->where(function ($q) {
                // an online order still waiting for its payment is not an order yet
                $q->whereNotIn('payment_method', self::ONLINE)->orWhere('is_paid', true)->orWhere('status', '!=', 'pending');
            });
    }

    public function index(Request $request)
    {
        $uid = $request->user()->id;
        $orders = $this->base($uid)->orderByDesc('created_at')->limit(60)->get();
        if ($orders->isEmpty()) return response()->json(['data' => []]);

        $restaurants = DB::table('restaurants')->whereIn('id', $orders->pluck('restaurant_id'))->get()->keyBy('id');
        $items = DB::table('order_items')->whereIn('order_id', $orders->pluck('id'))->get()->groupBy('order_id');

        return response()->json(['data' => $orders->map(fn ($o) => [
            'id' => $o->id,
            'order_number' => $o->order_number,
            'status' => $o->status,
            'active' => !in_array($o->status, self::DONE, true),
            'total' => (float) $o->total,
            'payment_method' => $o->payment_method,
            'is_paid' => (bool) $o->is_paid,
            'created_at' => $this->iso($o->created_at),
            'restaurant' => [
                'id' => $o->restaurant_id,
                'name' => $restaurants->get($o->restaurant_id)->name ?? '',
                'logo_url' => ($restaurants->get($o->restaurant_id)->logo ?? null) ? asset('storage/' . $restaurants->get($o->restaurant_id)->logo) : null,
            ],
            'items' => ($items->get($o->id) ?? collect())->map(fn ($i) => ['name' => $i->item_name, 'quantity' => (int) $i->quantity])->values(),
        ])->values()]);
    }

    private function iso($v): ?string
    {
        return $v ? \Carbon\Carbon::parse($v)->toIso8601String() : null;
    }

    private function eta(object $o, $items): array
    {
        $prep = (int) (DB::table('menu_items')->whereIn('id', $items->pluck('menu_item_id'))->max('prep_time_minutes') ?: 15);
        $start = $o->preparing_at ?? $o->accepted_at ?? $o->created_at;
        $elapsed = max(0, now()->diffInMinutes(\Carbon\Carbon::parse($start), true));
        $remaining = in_array($o->status, ['ready', 'dispatched', 'delivered'], true) ? 0 : max(2, $prep - (int) $elapsed);
        return ['prep_minutes' => $prep, 'prep_remaining_minutes' => $remaining];
    }

    public function show(Request $request, int $id)
    {
        $uid = $request->user()->id;
        $o = $this->base($uid)->where('id', $id)->first();
        abort_unless($o, 404, 'Order not found');

        $restaurant = DB::table('restaurants')->where('id', $o->restaurant_id)->first();
        $branch = DB::table('branches')->where('id', $o->branch_id)
            ->selectRaw('id, name, address, city, phone, ST_Y(location) as lat, ST_X(location) as lng')->first();
        $addr = $o->delivery_address_id ? DB::table('customer_addresses')->where('id', $o->delivery_address_id)
            ->selectRaw('label, address, details, city, ST_Y(location) as lat, ST_X(location) as lng')->first() : null;
        $items = DB::table('order_items')->where('order_id', $o->id)->get();
        $meta = json_decode($o->meta ?? '[]', true) ?: [];

        $assignment = DB::table('delivery_assignments')->where('order_id', $o->id)->first();
        $rider = null;
        if ($assignment) {
            $r = DB::table('rider_profiles')->join('users', 'users.id', '=', 'rider_profiles.user_id')
                ->where('rider_profiles.id', $assignment->rider_profile_id)
                ->selectRaw('users.name, users.phone, rider_profiles.vehicle_type, rider_profiles.license_plate, rider_profiles.rating, rider_profiles.updated_at as located_at, ST_Y(rider_profiles.current_location) as lat, ST_X(rider_profiles.current_location) as lng')
                ->first();
            if ($r && $o->status !== 'delivered') {
                $rider = [
                    'name' => $r->name, 'phone' => $r->phone, 'vehicle_type' => $r->vehicle_type, 'license_plate' => $r->license_plate,
                    'rating' => $r->rating !== null ? (float) $r->rating : null,
                    'lat' => $r->lat !== null ? (float) $r->lat : null, 'lng' => $r->lng !== null ? (float) $r->lng : null,
                    'located_at' => $this->iso($r->located_at),
                ];
            }
        }

        return response()->json([
            'id' => $o->id,
            'order_number' => $o->order_number,
            'status' => $o->status,
            'active' => !in_array($o->status, self::DONE, true),
            'total' => (float) $o->total,
            'subtotal' => (float) $o->subtotal,
            'payment_method' => $o->payment_method,
            'is_paid' => (bool) $o->is_paid,
            'notes' => $o->customer_notes,
            'reject_reason' => $meta['reject_reason'] ?? null,
            'restaurant' => ['id' => $o->restaurant_id, 'name' => $restaurant->name ?? '', 'logo_url' => ($restaurant->logo ?? null) ? asset('storage/' . $restaurant->logo) : null],
            'branch' => $branch ? [
                'name' => $branch->name, 'address' => trim($branch->address . ', ' . $branch->city, ', '), 'phone' => $branch->phone,
                'lat' => $branch->lat !== null ? (float) $branch->lat : null, 'lng' => $branch->lng !== null ? (float) $branch->lng : null,
            ] : null,
            'dropoff' => $addr ? [
                'label' => $addr->label, 'address' => $addr->address, 'details' => $meta['unit_details'] ?? $addr->details,
                'lat' => $addr->lat !== null ? (float) $addr->lat : null, 'lng' => $addr->lng !== null ? (float) $addr->lng : null,
            ] : null,
            'rider' => $rider,
            'timeline' => [
                'placed' => $this->iso($o->created_at), 'accepted' => $this->iso($o->accepted_at), 'preparing' => $this->iso($o->preparing_at), 'ready' => $this->iso($o->ready_at),
                'picked_up' => $this->iso($assignment->picked_up_at ?? null), 'delivered' => $this->iso($o->delivered_at), 'cancelled' => $this->iso($o->cancelled_at),
            ],
            'eta' => $this->eta($o, $items),
            'items' => $items->map(fn ($i) => [
                'name' => $i->item_name, 'quantity' => (int) $i->quantity, 'line_total' => (float) $i->subtotal,
                'options' => collect(json_decode($i->selected_modifiers ?? '[]', true) ?: [])->map(fn ($m) => $m['modifier'] ?? $m['name'] ?? null)->filter()->values(),
            ])->values(),
        ]);
    }

    /** Order-update feed built from the order timestamps, newest first. */
    public function notifications(Request $request)
    {
        $user = $request->user();

        // first time: treat everything that already happened as read
        $readAt = DB::table('users')->where('id', $user->id)->value('notifications_read_at');
        if (!$readAt) {
            DB::table('users')->where('id', $user->id)->update(['notifications_read_at' => now()]);
            $readAt = now()->toDateTimeString();
        }
        $readAt = \Carbon\Carbon::parse($readAt);

        $orders = $this->base($user->id)->where('created_at', '>=', now()->subDays(14))->get();
        $restaurants = DB::table('restaurants')->whereIn('id', $orders->pluck('restaurant_id'))->pluck('name', 'id');
        $assign = DB::table('delivery_assignments')->whereIn('order_id', $orders->pluck('id'))->get()->keyBy('order_id');
        $riders = DB::table('rider_profiles')->join('users', 'users.id', '=', 'rider_profiles.user_id')
            ->whereIn('rider_profiles.id', $assign->pluck('rider_profile_id'))->pluck('users.name', 'rider_profiles.id');

        $events = [];
        $add = function ($o, $key, $icon, $title, $body, $at) use (&$events) {
            if (!$at) return;
            $events[] = ['id' => "{$o->id}:{$key}", 'order_id' => $o->id, 'icon' => $icon, 'title' => $title, 'body' => $body,
                'at' => \Carbon\Carbon::parse($at)->toIso8601String()];
        };

        foreach ($orders as $o) {
            $rn = $restaurants[$o->restaurant_id] ?? 'the restaurant';
            $n = $o->order_number;
            $a = $assign->get($o->id);
            $rider = $a ? ($riders[$a->rider_profile_id] ?? 'Your driver') : null;
            $add($o, 'placed', 'receipt_long', 'Order placed', "Your order {$n} from {$rn} was placed.", $o->created_at);
            $add($o, 'accepted', 'storefront', 'Restaurant accepted your order', "{$rn} accepted order {$n}.", $o->accepted_at);
            $add($o, 'preparing', 'skillet', 'Your food is being prepared', "{$rn} started preparing order {$n}.", $o->preparing_at);
            $add($o, 'ready', 'takeout_dining', 'Your order is ready', "Order {$n} is ready and waiting for the driver.", $o->ready_at);
            if ($a) {
                $add($o, 'assigned', 'two_wheeler', 'Driver assigned', "{$rider} will deliver order {$n}.", $a->assigned_at);
                $add($o, 'picked_up', 'delivery_dining', 'Driver picked up your order', "{$rider} is on the way with order {$n}.", $a->picked_up_at);
            }
            $add($o, 'delivered', 'task_alt', 'Order delivered', "Order {$n} was delivered. Enjoy your meal!", $o->delivered_at);
            if ($o->status === 'cancelled') $add($o, 'cancelled', 'cancel', 'Order cancelled', "Order {$n} was cancelled.", $o->cancelled_at ?? $o->updated_at);
            if ($o->status === 'rejected') $add($o, 'rejected', 'block', 'Restaurant could not accept your order', "{$rn} rejected order {$n}.", $o->updated_at);
        }

        usort($events, fn ($x, $y) => strcmp($y['at'], $x['at']));
        $events = array_slice($events, 0, 30);
        foreach ($events as &$e) $e['unread'] = \Carbon\Carbon::parse($e['at'])->gt($readAt);

        return response()->json(['unread' => collect($events)->where('unread', true)->count(), 'data' => $events]);
    }

    public function markNotificationsRead(Request $request)
    {
        DB::table('users')->where('id', $request->user()->id)->update(['notifications_read_at' => now()]);
        return response()->json(['ok' => true]);
    }
}
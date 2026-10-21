<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Restaurant;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Notifications for the admin dashboard, built from live data and filtered by what the signed-in role may see
 * (the Eloquent tenant scopes already limit an owner or kitchen user to their own restaurants).
 */
class AdminNotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $readAt = DB::table('users')->where('id', $user->id)->value('admin_notifications_read_at');
        $readAt = $readAt ? Carbon::parse($readAt) : now()->subDay();

        $events = Cache::remember("admin:notifications:{$user->id}", 10, fn () => $this->events($user));

        usort($events, fn ($a, $b) => strcmp($b['at'], $a['at']));
        $events = array_slice($events, 0, 30);
        foreach ($events as &$e) $e['unread'] = Carbon::parse($e['at'])->gt($readAt);

        return response()->json(['unread' => collect($events)->where('unread', true)->count(), 'data' => $events]);
    }

    private function events($user): array
    {
        $events = [];
        $add = function (string $id, string $type, string $title, string $body, $at, string $path) use (&$events) {
            if (!$at) return;
            $events[] = ['id' => $id, 'type' => $type, 'title' => $title, 'body' => $body, 'at' => Carbon::parse($at)->toIso8601String(), 'path' => $path];
        };
        $money = fn ($n) => '$' . number_format((float) $n, 2);

        if ($user->role === 'rider') {
            $profile = DB::table('rider_profiles')->where('user_id', $user->id)->value('id');
            $rows = DB::table('delivery_assignments')->where('delivery_assignments.rider_profile_id', $profile)->where('delivery_assignments.created_at', '>=', now()->subDays(3))
                ->join('orders', 'orders.id', '=', 'delivery_assignments.order_id')
                ->get(['delivery_assignments.id', 'delivery_assignments.status', 'delivery_assignments.assigned_at', 'delivery_assignments.delivered_at', 'delivery_assignments.earnings', 'orders.order_number']);
            foreach ($rows as $r) {
                $add("a{$r->id}", 'delivery', 'New delivery assigned', "Order {$r->order_number} is waiting for you.", $r->assigned_at, '/driver');
                if ($r->status === 'delivered') $add("d{$r->id}", 'success', 'Delivery completed', "Order {$r->order_number} · you earned " . $money($r->earnings), $r->delivered_at, '/driver');
            }
        } else {
            $isStaff = in_array($user->role, ['super_admin', 'admin'], true);

            // which alert groups the platform settings let through
            $orderAlerts = \App\Services\PlatformSettings::bool('order_alerts', true);
            $riderAlerts = \App\Services\PlatformSettings::bool('rider_alerts', true);
            $refundAlerts = \App\Services\PlatformSettings::bool('refund_alerts', true);

            // new orders and what happened to them (scoped to the restaurants this user may see)
            $orders = Order::with('restaurant:id,name')->where('created_at', '>=', now()->subDay())->latest()->limit(60)->get();
            $assigned = DB::table('delivery_assignments')->whereIn('order_id', $orders->pluck('id'))->pluck('order_id')->flip();
            foreach ($orders as $o) {
                $rn = $o->restaurant?->name ?? 'a restaurant';
                if ($orderAlerts) {
                    $add("o{$o->id}", 'order', 'New order ' . $o->order_number, "{$rn} · " . $money($o->total), $o->created_at, '/orders');
                }
                if ($riderAlerts && $o->status === 'ready' && !$assigned->has($o->id)) {
                    $add("r{$o->id}", 'warning', 'Order ready — needs a rider', "{$o->order_number} from {$rn} is waiting for dispatch.", $o->ready_at ?? $o->updated_at, '/dispatch');
                }
                if ($refundAlerts && in_array($o->status, ['cancelled', 'rejected'], true)) {
                    $add("x{$o->id}", 'error', 'Order ' . $o->status, "{$o->order_number} from {$rn} was {$o->status}.", $o->cancelled_at ?? $o->updated_at, '/orders');
                }
            }

            if ($isStaff) {
                foreach (Restaurant::where('status', 'pending')->where('created_at', '>=', now()->subDays(14))->latest()->limit(10)->get() as $r) {
                    $add("p{$r->id}", 'partner', 'New partner registration', "{$r->name} ({$r->cuisine_type}) is waiting for verification.", $r->created_at, '/restaurants');
                }
                foreach (DB::table('rider_profiles')->join('users', 'users.id', '=', 'rider_profiles.user_id')->where('rider_profiles.created_at', '>=', now()->subDays(7))
                    ->latest('rider_profiles.created_at')->limit(5)->get(['rider_profiles.id', 'rider_profiles.created_at', 'users.name', 'rider_profiles.vehicle_type']) as $d) {
                    $add("n{$d->id}", 'driver', 'New driver registered', "{$d->name} ({$d->vehicle_type}) joined the delivery team.", $d->created_at, '/dispatch');
                }
            }
        }

        return $events;
    }

    /** Everything the admin shell needs on first load, in ONE request: profile, menu modules and notifications. */
    public function bootstrap(Request $request)
    {
        return response()->json([
            'user' => app(AuthController::class)->me($request)->getData(true),
            // "*" = unrestricted (super admin); otherwise the "<page>.<action>" permissions the role holds
            'permissions' => $request->user()->hasRole('super_admin')
                ? ['*']
                : $request->user()->getAllPermissions()->pluck('name')->filter(fn ($n) => str_contains($n, '.'))->values(),
            'navigation' => app(NavigationItemsController::class)->index($request)->getData(true)['data'] ?? [],
            'notifications' => $this->index($request)->getData(true),
            // so the shell can lock the screen and explain the access window
            'access_window' => \App\Services\AccessWindowService::statusForUser($request->user()),
            'platform' => \App\Services\PlatformSettings::publicConfig(),
        ]);
    }

    public function markRead(Request $request)
    {
        DB::table('users')->where('id', $request->user()->id)->update(['admin_notifications_read_at' => now()]);
        return response()->json(['ok' => true]);
    }
}
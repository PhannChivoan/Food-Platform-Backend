<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\RiderProfile;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function stats(Request $request)
    {
        $today = now();
        $startOfDay = $today->copy()->startOfDay();
        $endOfDay = $today->copy()->endOfDay();

        $stats = [
            'orders_today' => Order::whereBetween('created_at', [$startOfDay, $endOfDay])->count(),
            'revenue_today' => Order::whereBetween('created_at', [$startOfDay, $endOfDay])->sum('total'),
            'active_riders' => RiderProfile::where('is_online', true)->count(),
            'total_restaurants' => Restaurant::count(),
            'pending_orders' => Order::where('status', 'pending')->count(),
            'avg_delivery_time' => $this->calculateAverageDeliveryTime(),
        ];

        return response()->json($stats);
    }

    /**
     * Everything the dashboard shows, in one request. Numbers follow the Eloquent tenant scopes, so a restaurant
     * owner sees their own restaurants and an admin sees the whole platform. Cached for 20 s per user.
     */
    public function overview(Request $request)
    {
        $user = $request->user();
        $branch = $request->integer('branch_id') ?: null; // one branch, or null = every branch the user may see
        $data = Cache::remember("dashboard:overview:{$user->id}:{$branch}", 20, fn () => $this->buildOverview($branch));
        return response()->json($data);
    }

    private function buildOverview(?int $branch = null): array
    {
        // orders (and deliveries) limited to the selected branch
        $O = fn () => Order::query()->when($branch, fn ($q) => $q->where('orders.branch_id', $branch));
        $tz = DB::table('platform_settings')->where('key', 'timezone')->value('value') ?: config('app.timezone');
        $now = Carbon::now($tz);
        $todayStart = $now->copy()->startOfDay();
        $yStart = $todayStart->copy()->subDay();
        $weekStart = $todayStart->copy()->subDays(6);

        $live = fn ($q) => $q->whereNotIn('orders.status', ['cancelled', 'rejected']);
        $sum = fn ($from, $to) => (float) $live($O()->whereBetween('created_at', [$from->copy()->utc(), $to->copy()->utc()]))->sum('total');
        $cnt = fn ($from, $to) => $live($O()->whereBetween('created_at', [$from->copy()->utc(), $to->copy()->utc()]))->count();

        $ordersToday = $cnt($todayStart, $now);
        $ordersYest = $cnt($yStart, $todayStart);
        $revToday = $sum($todayStart, $now);
        $revYest = $sum($yStart, $todayStart);
        $pct = fn ($a, $b) => $b > 0 ? round((($a - $b) / $b) * 100, 1) : null;

        // today by hour + the last 7 days (bucketed in the platform's time zone)
        $week = $live($O()->where('created_at', '>=', $weekStart->copy()->utc()))->get(['created_at', 'total']);
        $hourly = array_fill(0, 24, 0);
        $daily = [];
        for ($i = 0; $i < 7; $i++) $daily[$weekStart->copy()->addDays($i)->toDateString()] = ['orders' => 0, 'revenue' => 0.0];
        foreach ($week as $o) {
            $t = Carbon::parse($o->created_at)->setTimezone($tz);
            if ($t->gte($todayStart)) $hourly[$t->hour]++;
            $k = $t->toDateString();
            if (isset($daily[$k])) { $daily[$k]['orders']++; $daily[$k]['revenue'] += (float) $o->total; }
        }

        $activeAssignments = DB::table('delivery_assignments')->whereIn('status', ['assigned', 'picked_up'])
            ->when($branch, fn ($q) => $q->whereIn('order_id', DB::table('orders')->where('branch_id', $branch)->select('id')));
        $late = (clone $activeAssignments)->where('assigned_at', '<', now()->subMinutes(30))->count();
        $avgMin = $O()->whereNotNull('delivered_at')->whereNotNull('accepted_at')->where('delivered_at', '>=', $weekStart->copy()->utc())
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, accepted_at, delivered_at)) as avg')->value('avg');
        $delivered7 = $O()->whereNotNull('delivered_at')->where('delivered_at', '>=', $weekStart->copy()->utc());
        $deliveredCount = (clone $delivered7)->count();
        $onTime = (clone $delivered7)->whereRaw('TIMESTAMPDIFF(MINUTE, created_at, delivered_at) <= 45')->count();

        $queue = $O()->whereIn('status', ['pending', 'accepted', 'preparing', 'ready']);
        $statusToday = $O()->where('created_at', '>=', $todayStart->copy()->utc())->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status');

        $top = $live($O()->where('orders.created_at', '>=', $weekStart->copy()->utc()))
            ->join('restaurants', 'restaurants.id', '=', 'orders.restaurant_id')
            ->groupBy('restaurants.id', 'restaurants.name')->orderByDesc(DB::raw('SUM(orders.total)'))->limit(5)
            ->get(['restaurants.id', 'restaurants.name', DB::raw('COUNT(*) as orders'), DB::raw('SUM(orders.total) as revenue')]);

        $recent = $O()->with('restaurant:id,name')->latest()->limit(8)->get()->map(fn ($o) => [
            'id' => $o->id, 'order' => $o->order_number, 'restaurant' => $o->restaurant?->name, 'amount' => (float) $o->total,
            'status' => $o->status, 'at' => $o->created_at?->toIso8601String(),
        ]);

        return [
            'generated_at' => now()->toIso8601String(),
            'kpis' => [
                'orders_today' => $ordersToday, 'orders_change' => $pct($ordersToday, $ordersYest),
                'revenue_today' => round($revToday, 2), 'revenue_change' => $pct($revToday, $revYest),
                'active_deliveries' => (clone $activeAssignments)->count(), 'late_deliveries' => $late,
                'avg_delivery_minutes' => $avgMin !== null ? (int) round($avgMin) : null,
            ],
            'health' => [
                'kitchen_queue' => (clone $queue)->count(),
                'urgent_orders' => (clone $queue)->where('status', 'pending')->where('created_at', '<', now()->subMinutes(10))->count(),
                'riders_online' => RiderProfile::where('is_online', true)->count(),
                'riders_active' => (clone $activeAssignments)->distinct('rider_profile_id')->count('rider_profile_id'),
                'restaurants_active' => Restaurant::where('status', 'verified')->count(),
                'restaurants_pending' => Restaurant::where('status', 'pending')->count(),
                'on_time_rate' => $deliveredCount > 0 ? (int) round($onTime / $deliveredCount * 100) : null,
            ],
            'hourly' => collect($hourly)->map(fn ($v, $h) => ['hour' => $h, 'value' => $v])->values(),
            'daily' => collect($daily)->map(fn ($d, $date) => ['date' => $date, 'orders' => $d['orders'], 'revenue' => round($d['revenue'], 2)])->values(),
            'status_today' => $statusToday,
            'top_restaurants' => $top,
            'recent_orders' => $recent,
        ];
    }
    private function calculateAverageDeliveryTime()
    {
        // Average time between 'accepted_at' and 'delivered_at' in minutes
        $avg = Order::whereNotNull('delivered_at')
                    ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, accepted_at, delivered_at)) as avg')
                    ->value('avg');
        return round($avg ?? 0);
    }
}
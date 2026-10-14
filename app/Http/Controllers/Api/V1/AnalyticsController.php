<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\RiderProfile;
use App\Models\DeliveryAssignment;
use App\Models\DeliveryZone;
use App\Models\Comment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    public function orderHeatmap(Request $request)
    {
        $query = Order::select(DB::raw('HOUR(created_at) as hour, DAYNAME(created_at) as day, COUNT(*) as count'));

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        } else {
            $date = $request->date ?? now()->toDateString();
            $start = now()->parse($date)->startOfDay();
            $end = $start->copy()->endOfDay();
            $query->whereBetween('created_at', [$start, $end]);
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }

        if ($request->filled('restaurant_id')) {
            $query->where('restaurant_id', $request->restaurant_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $data = $query->groupBy('hour', 'day')->get();
        return response()->json($data);
    }

    public function slaPerformance(Request $request)
    {
        $query = Order::whereNotNull('delivered_at');

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }
        if ($request->filled('restaurant_id')) {
            $query->where('restaurant_id', $request->restaurant_id);
        }

        $avg = $query->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, accepted_at, delivered_at)) as avg_delivery')
                     ->value('avg_delivery');

        $totalOrders = $query->count();
        $withinSla = $query->whereRaw('TIMESTAMPDIFF(MINUTE, accepted_at, delivered_at) <= 30')->count();

        return response()->json([
            'average_delivery_minutes' => round($avg ?? 0),
            'sla_target' => 30,
            'within_sla' => $avg !== null && $avg <= 30,
            'total_delivered' => $totalOrders,
            'within_sla_count' => $withinSla,
            'sla_percentage' => $totalOrders > 0 ? round(($withinSla / $totalOrders) * 100) : 0,
        ]);
    }

    public function revenueSplit(Request $request)
    {
        $query = Restaurant::withSum('orders', 'total');

        if ($request->filled('restaurant_id')) {
            $query->where('id', $request->restaurant_id);
        }

        if ($request->filled('date_from') || $request->filled('date_to')) {
            $query->whereHas('orders', function ($q) use ($request) {
                if ($request->filled('date_from')) {
                    $q->where('created_at', '>=', $request->date_from);
                }
                if ($request->filled('date_to')) {
                    $q->where('created_at', '<=', $request->date_to . ' 23:59:59');
                }
            });
        }

        $restaurants = $query->get();
        $platformCommission = \App\Services\Commission::platformRate() / 100;

        $data = $restaurants->map(fn($r) => [
            'restaurant_id' => $r->id,
            'restaurant' => $r->name,
            'gross_revenue' => $r->orders_sum_total ?? 0,
            'commission' => round(($r->orders_sum_total ?? 0) * $platformCommission, 2),
            'net_revenue' => round(($r->orders_sum_total ?? 0) * (1 - $platformCommission), 2),
        ]);

        return response()->json($data);
    }

    public function riderEfficiency(Request $request)
    {
        $query = RiderProfile::with('user:id,name');

        if ($request->filled('date_from') || $request->filled('date_to')) {
            $query->whereHas('deliveryAssignments', function ($q) use ($request) {
                if ($request->filled('date_from')) {
                    $q->where('assigned_at', '>=', $request->date_from);
                }
                if ($request->filled('date_to')) {
                    $q->where('assigned_at', '<=', $request->date_to . ' 23:59:59');
                }
            });
        }

        $riders = $query->withCount('deliveryAssignments')->get();

        $data = $riders->map(function ($r) {
            $assignments = $r->deliveryAssignments;
            $completed = $assignments->where('status', 'delivered');
            $avgTime = $completed->filter(fn($a) => $a->picked_up_at && $a->delivered_at)
                ->map(fn($a) => $a->picked_up_at->diffInMinutes($a->delivered_at))
                ->avg();

            return [
                'rider_id' => $r->id,
                'rider' => $r->user->name ?? 'Unknown',
                'avatar' => $r->user->avatar ?? null,
                'total_trips' => $r->delivery_assignments_count,
                'completed_trips' => $completed->count(),
                'rating' => $r->rating,
                'avg_delivery_minutes' => round($avgTime ?? 0),
                'earnings' => $r->total_earnings,
                'is_online' => $r->is_online,
            ];
        });

        return response()->json($data);
    }

    public function customerSatisfaction(Request $request)
    {
        $query = Comment::where('commentable_type', 'App\Models\Order');

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }

        $avg = $query->avg('rating');
        $total = $query->count();
        $distribution = $query->select('rating', DB::raw('count(*) as count'))
            ->groupBy('rating')
            ->orderBy('rating')
            ->get();

        return response()->json([
            'average_rating' => round($avg ?? 0, 2),
            'total_reviews' => $total,
            'distribution' => $distribution,
        ]);
    }

    public function exportReport(Request $request)
    {
        return response()->json(['message' => 'Report generation – use Excel package']);
    }

    public function liveMonitor(Request $request)
    {
        $today = now();
        $stats = [
            'orders_in_last_15min' => Order::where('created_at', '>=', now()->subMinutes(15))->count(),
            'active_riders' => RiderProfile::where('is_online', true)->count(),
            'revenue_today' => Order::whereBetween('created_at', [$today->copy()->startOfDay(), $today->copy()->endOfDay()])->sum('total'),
            'orders_today' => Order::whereBetween('created_at', [$today->copy()->startOfDay(), $today->copy()->endOfDay()])->count(),
            'completed_today' => Order::where('status', 'delivered')->whereBetween('created_at', [$today->copy()->startOfDay(), $today->copy()->endOfDay()])->count(),
        ];
        return response()->json($stats);
    }

    public function deliveryPerformance(Request $request)
    {
        $slaTarget = 30;
        $durationExpr = 'TIMESTAMPDIFF(MINUTE, da.assigned_at, da.delivered_at)';
        $onTimeExpr = $durationExpr . ' <= ' . $slaTarget;

        $base = DB::table('delivery_assignments as da')
            ->join('orders as o', 'o.id', '=', 'da.order_id')
            ->where('da.status', 'delivered')
            ->whereNotNull('da.assigned_at')
            ->whereNotNull('da.delivered_at');

        if ($request->filled('date_from')) {
            $base->where('da.delivered_at', '>=', $request->date_from . ' 00:00:00');
        }
        if ($request->filled('date_to')) {
            $base->where('da.delivered_at', '<=', $request->date_to . ' 23:59:59');
        }
        if ($request->filled('zone_id')) {
            $base->whereExists(function ($q) use ($request) {
                $q->selectRaw('1')
                    ->from('zone_branch')
                    ->whereColumn('zone_branch.branch_id', 'o.branch_id')
                    ->where('zone_branch.delivery_zone_id', $request->zone_id);
            });
        }

        $totalDelivered = (clone $base)->count();
        $avgDelivery = round((float) ((clone $base)->avg(DB::raw($durationExpr)) ?? 0));
        $onTimeCount = (clone $base)->whereRaw($onTimeExpr)->count();

        $pickupQuery = DB::table('delivery_assignments')
            ->whereNotNull('assigned_at')
            ->whereNotNull('picked_up_at');
        if ($request->filled('date_from')) {
            $pickupQuery->where('picked_up_at', '>=', $request->date_from . ' 00:00:00');
        }
        if ($request->filled('date_to')) {
            $pickupQuery->where('picked_up_at', '<=', $request->date_to . ' 23:59:59');
        }
        $avgPickup = round((float) ($pickupQuery->avg(DB::raw('TIMESTAMPDIFF(MINUTE, assigned_at, picked_up_at)')) ?? 0));

        $zones = DeliveryZone::orderBy('name')->get();

        $zoneRows = $zones->map(function ($zone) use ($base, $durationExpr, $onTimeExpr, $slaTarget) {
            $zoneBase = (clone $base)->whereExists(function ($q) use ($zone) {
                $q->selectRaw('1')
                    ->from('zone_branch')
                    ->whereColumn('zone_branch.branch_id', 'o.branch_id')
                    ->where('zone_branch.delivery_zone_id', $zone->id);
            });

            $trips = $zoneBase->count();
            $average = round((float) ($zoneBase->avg(DB::raw($durationExpr)) ?? 0));
            $onTime = (clone $zoneBase)->whereRaw($onTimeExpr)->count();

            return [
                'zone_id' => $zone->id,
                'zone' => $zone->name,
                'trips' => $trips,
                'average' => $average,
                'sla' => $slaTarget,
                'on_time_rate' => $trips > 0 ? round(($onTime / $trips) * 100) : 0,
                'late' => max(0, $trips - $onTime),
            ];
        })->filter(fn ($row) => $row['trips'] > 0)->values()->all();

        $hourly = (clone $base)
            ->selectRaw(
                "HOUR(da.delivered_at) as hour, COUNT(*) as trips, "
                . "SUM(CASE WHEN {$onTimeExpr} THEN 1 ELSE 0 END) as on_time, "
                . "ROUND(AVG({$durationExpr})) as average"
            )
            ->groupBy(DB::raw('HOUR(da.delivered_at)'))
            ->orderBy(DB::raw('HOUR(da.delivered_at)'))
            ->get()
            ->map(fn ($h) => [
                'hour' => (int) $h->hour,
                'trips' => (int) $h->trips,
                'on_time' => (int) $h->on_time,
                'average' => (int) ($h->average ?? 0),
            ])
            ->values()
            ->all();

        return response()->json([
            'stats' => [
                'sla_target_minutes' => $slaTarget,
                'total_delivered' => $totalDelivered,
                'average_delivery_minutes' => $avgDelivery,
                'average_pickup_minutes' => $avgPickup,
                'within_sla_count' => $onTimeCount,
                'on_time_rate' => $totalDelivered > 0 ? round(($onTimeCount / $totalDelivered) * 100) : 0,
                'late_orders' => max(0, $totalDelivered - $onTimeCount),
                'active_trips' => DeliveryAssignment::whereIn('status', ['assigned', 'picked_up'])->count(),
            ],
            'zones' => $zoneRows,
            'hourly' => $hourly,
        ]);
    }
}

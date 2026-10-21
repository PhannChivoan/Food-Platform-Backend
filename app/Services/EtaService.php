<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Estimated delivery time shown to the customer: kitchen time plus travel time. */
class EtaService
{
    private const SPEED_KMH = 22;      // bike in city traffic
    private const HANDOVER_MIN = 5;    // rider pick-up + hand-over

    /** @return array{minutes:int, at:string}|null  null once the order is finished */
    public static function for(object $order): ?array
    {
        if (in_array($order->status, ['delivered', 'cancelled', 'rejected'], true)) {
            return null;
        }

        $prep = (int) DB::table('order_items')
            ->join('menu_items', 'menu_items.id', '=', 'order_items.menu_item_id')
            ->where('order_items.order_id', $order->id)
            ->max('menu_items.prep_time_minutes');
        $prep = $prep ?: PlatformSettings::int('prep_time_minutes', 30);

        // pickup: the customer comes to the restaurant, so there is no rider, hand-over or travel time
        $pickup = $order->payment_method === 'pickup';
        $travel = $pickup ? 0 : self::travelMinutes($order);

        // the clock starts when the kitchen took the order
        $start = Carbon::parse($order->accepted_at ?? $order->created_at);
        if (!empty($order->ready_at)) {
            $start = Carbon::parse($order->ready_at);
            $prep = 0;
        }

        $at = $start->copy()->addMinutes($prep + ($pickup ? 0 : self::HANDOVER_MIN) + $travel);
        $minutes = max(0, (int) ceil(now()->diffInMinutes($at, false)));

        return ['minutes' => $minutes, 'at' => $at->toIso8601String()];
    }

    private static function travelMinutes(object $order): int
    {
        $row = DB::selectOne(
            'select ST_Distance_Sphere(b.location, a.location) as metres
             from branches b, customer_addresses a
             where b.id = ? and a.id = ?',
            [$order->branch_id, $order->delivery_address_id]
        );

        $km = $row && $row->metres !== null ? $row->metres / 1000 : 3; // no coordinates: assume 3 km

        return (int) max(5, ceil($km / self::SPEED_KMH * 60));
    }
}

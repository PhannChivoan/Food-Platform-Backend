<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The delivery lifecycle (assigned -> picked_up -> delivered) shared by the admin
 * "Live Deliveries" buttons and the rider portal. Query builder only: the Eloquent
 * tenant scopes hide orders from rider accounts.
 */
class DeliveryService
{
    public static function pickUp(int $assignmentId): void
    {
        DB::table('delivery_assignments')->where('id', $assignmentId)->update([
            'status' => 'picked_up',
            'picked_up_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Marks the delivery and its order as delivered and credits the rider once. */
    public static function complete(int $assignmentId, ?string $proofPhoto = null): void
    {
        DB::transaction(function () use ($assignmentId, $proofPhoto) {
            $a = DB::table('delivery_assignments')->where('id', $assignmentId)->lockForUpdate()->first();
            if (!$a || $a->status === 'delivered') return;

            $order = DB::table('orders')->where('id', $a->order_id)->first();
            // Rider fee: 10% of the food subtotal, at least $1.50, unless one was already set.
            $fee = (float) $a->earnings > 0 ? (float) $a->earnings : max(1.5, round(((float) ($order->subtotal ?? 0)) * 0.10, 2));

            DB::table('delivery_assignments')->where('id', $a->id)->update(array_filter([
                'status' => 'delivered',
                'picked_up_at' => $a->picked_up_at ?? now(),
                'delivered_at' => now(),
                'earnings' => $fee,
                'proof_photo' => $proofPhoto,
                'updated_at' => now(),
            ], fn ($v) => $v !== null));

            DB::table('orders')->where('id', $a->order_id)->update([
                'status' => 'delivered',
                'delivered_at' => now(),
                // cash orders are settled by the rider at the door
                'is_paid' => DB::raw("CASE WHEN payment_method = 'cash' THEN 1 ELSE is_paid END"),
                'updated_at' => now(),
            ]);

            DB::table('rider_profiles')->where('id', $a->rider_profile_id)->update([
                'total_trips' => DB::raw('total_trips + 1'),
                'total_earnings' => DB::raw('total_earnings + ' . number_format($fee, 2, '.', '')),
                'is_available' => true,
                'updated_at' => now(),
            ]);
        });
    }
}
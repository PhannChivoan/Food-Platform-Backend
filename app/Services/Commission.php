<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The platform has no subscription: it deducts a commission (%) from the food subtotal of every customer order.
 * A restaurant can carry its own rate in restaurants.settings.commission_rate; otherwise the platform rate applies.
 */
class Commission
{
    public static function platformRate(): float
    {
        return (float) (DB::table('platform_settings')->where('key', 'commission_rate')->value('value') ?? 12);
    }

    public static function rateFor(int $restaurantId): float
    {
        $settings = json_decode((string) DB::table('restaurants')->where('id', $restaurantId)->value('settings'), true) ?: [];
        return (float) ($settings['commission_rate'] ?? self::platformRate());
    }

    /** @return array{commission_rate: float, platform_fee: float, restaurant_payout: float} */
    public static function split(int $restaurantId, float $subtotal): array
    {
        $rate = self::rateFor($restaurantId);
        $fee = round($subtotal * $rate / 100, 2);
        return ['commission_rate' => $rate, 'platform_fee' => $fee, 'restaurant_payout' => round($subtotal - $fee, 2)];
    }
}
<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Coupon rules used at checkout. Coupons are the promotions managed in the admin (Growth -> Promotions):
 * a code is valid inside its dates, for its restaurant (or every restaurant when platform-wide),
 * within the total and per-customer usage limits. Query builder only (the Eloquent scopes hide data from customers).
 */
class CouponService
{
    /** @return array{ok: bool, message: string, promotion: ?object, discount: float} */
    public static function evaluate(string $code, int $userId, int $restaurantId, float $subtotal): array
    {
        $fail = fn (string $m) => ['ok' => false, 'message' => $m, 'promotion' => null, 'discount' => 0.0];
        $code = trim($code);
        if ($code === '') return $fail('Enter a promo code.');

        $p = DB::table('promotions')->whereRaw('LOWER(code) = ?', [strtolower($code)])->first();
        if (!$p || !$p->is_active) return $fail('This promo code is not valid.');
        if ($p->starts_at && now()->lt($p->starts_at)) return $fail('This promo code is not active yet.');
        if ($p->expires_at && now()->gt($p->expires_at)) return $fail('This promo code has expired.');
        if ($p->restaurant_id && (int) $p->restaurant_id !== $restaurantId) return $fail('This promo code is for a different restaurant.');

        $used = DB::table('promotion_redemptions')->where('promotion_id', $p->id)->where('status', 'success');
        if ($p->usage_limit && (clone $used)->count() >= $p->usage_limit) return $fail('This promo code has reached its usage limit.');
        if ($p->usage_limit_per_user && (clone $used)->where('user_id', $userId)->count() >= $p->usage_limit_per_user) {
            return $fail('You have already used this promo code the maximum number of times.');
        }

        $discount = $p->type === 'percentage'
            ? $subtotal * (float) $p->value / 100
            : (float) $p->value;
        if ($p->type === 'percentage' && $p->max_discount) $discount = min($discount, (float) $p->max_discount);
        $discount = round(min($discount, $subtotal), 2);
        if ($discount <= 0) return $fail('This promo code gives no discount on this order.');

        return ['ok' => true, 'message' => 'Promo code applied.', 'promotion' => $p, 'discount' => $discount];
    }
}
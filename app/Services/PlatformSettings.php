<?php

namespace App\Services;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Single reader for the platform_settings table.
 *
 * Every value written from /admin/settings is read back through here so a setting
 * actually drives the system instead of only being stored. Reads are cached for a
 * short while (settings change rarely) and the cache is dropped the moment the
 * settings are saved.
 */
class PlatformSettings
{
    private const CACHE_KEY = 'platform_settings:all';
    private const TTL = 120; // seconds

    /** @var array<string,string>|null per-request memo, so one request never re-queries */
    private static ?array $memo = null;

    /** @return array<string,string> raw key => stored value */
    public static function raw(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $rows = Cache::remember(self::CACHE_KEY, self::TTL, fn () =>
            DB::table('platform_settings')->pluck('value', 'key')->map(
                fn ($v) => $v === null ? '' : (string) $v
            )->all()
        );

        return self::$memo = $rows;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = self::raw()[$key] ?? '';
        return $value === '' ? $default : $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);
        return $value === null || !is_numeric($value) ? $default : (int) $value;
    }

    public static function float(string $key, float $default = 0.0): float
    {
        $value = self::get($key);
        return $value === null || !is_numeric($value) ? $default : (float) $value;
    }

    /** Drop the cache so a save is visible to the very next request. */
    public static function forget(): void
    {
        self::$memo = null;
        Cache::forget(self::CACHE_KEY);
    }

    public static function timezone(): string
    {
        $tz = self::get('timezone');
        return $tz && in_array($tz, timezone_identifiers_list(), true) ? $tz : config('app.timezone', 'UTC');
    }

    /**
     * Current time in the platform timezone. Windows are configured in the admin's
     * own timezone, so "22:00" has to mean 22:00 Phnom Penh, not 22:00 UTC.
     */
    public static function now(): \Carbon\CarbonImmutable
    {
        return \Carbon\CarbonImmutable::now(self::timezone());
    }

    /** Payment methods customers may pick, derived from payments settings. */
    public static function paymentMethods(): array
    {
        $methods = [];

        if (self::bool('cash_on_delivery', true)) {
            $methods[] = 'cash';
        }

        // the default provider decides which online option is offered
        match (self::get('payment_provider', 'cod')) {
            'aba' => $methods[] = 'aba_khqr',
            'stripe' => $methods[] = 'card',
            default => null,
        };

        return array_values(array_unique($methods));
    }

    /** Everything the storefront needs before signing in (no token required). */
    public static function publicConfig(): array
    {
        return [
            'platform_name' => self::get('platform_name', 'FoodOrder Platform'),
            'support_email' => self::get('support_email', ''),
            'currency' => self::get('currency', 'USD'),
            'timezone' => self::timezone(),
            'brand_logo' => self::assetUrl('brand_logo'),
            'brand_favicon' => self::assetUrl('brand_favicon'),
            'maintenance_mode' => self::bool('maintenance_mode'),
            'cash_on_delivery' => self::bool('cash_on_delivery', true),
            'payment_provider' => self::get('payment_provider', 'cod'),
            'payment_methods' => self::paymentMethods(),
            'commission_rate' => self::float('commission_rate', 12),
            'default_prep_time_minutes' => self::int('prep_time_minutes', 30),
            'auto_accept_orders' => self::bool('auto_accept_orders', true),
        ];
    }

    private static function assetUrl(string $key): ?string
    {
        $path = PlatformSetting::where('key', $key)->value('value');
        return $path ? asset('storage/' . $path) : null;
    }
}
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
    /** The only currencies the platform (and ABA PayWay) works in. Prices are stored in US dollars. */
    public const CURRENCIES = ['USD', 'KHR'];

    /** Currency shown to customers: USD or KHR (anything else falls back to USD). */
    public static function currency(): string
    {
        $c = strtoupper((string) self::get('currency', 'USD'));

        return in_array($c, self::CURRENCIES, true) ? $c : 'USD';
    }

    /** Riel per 1 US dollar, used when the platform currency is KHR. */
    public static function exchangeRate(): float
    {
        return max(1000.0, min(10000.0, self::float('exchange_rate', 4100)));
    }

    /** True while the saved currency is one PayWay can charge (USD or KHR). Online payment is switched off otherwise. */
    public static function onlinePaymentsSupported(): bool
    {
        return in_array(strtoupper((string) self::get('currency', 'USD')), self::CURRENCIES, true);
    }

    /** An amount stored in dollars, as PayWay wants it in the platform currency: USD "4.50", KHR whole riel "18500". */
    public static function payable(float $usd): string
    {
        if (self::currency() === 'KHR') {
            return number_format(round($usd * self::exchangeRate() / 100) * 100, 0, '.', '');
        }

        return number_format($usd, 2, '.', '');
    }

    public static function paymentMethods(): array
    {
        $methods = [];

        if (self::bool('cash_on_delivery', true)) {
            $methods[] = 'cash';
        }

        // the default provider decides which online option is offered
        // online payment only works in USD or KHR: any other saved currency hides ABA and card
        if (self::onlinePaymentsSupported()) {
            match (self::get('payment_provider', 'cod')) {
                'aba' => $methods[] = 'aba_khqr',
                'stripe' => $methods[] = 'card',
                default => null,
            };
        }

        // collecting the order at the restaurant (and paying there) is always possible
        $methods[] = 'pickup';

        return array_values(array_unique($methods));
    }

    /** Everything the storefront needs before signing in (no token required). */
    public static function publicConfig(): array
    {
        return [
            'platform_name' => self::get('platform_name', 'FoodOrder Platform'),
            'support_email' => self::get('support_email', ''),
            'currency' => self::currency(),
            'exchange_rate' => self::exchangeRate(),
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
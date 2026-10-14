<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class Tenant
{
    private static array $cache = [];

    public static function isAdmin(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'admin'], true);
    }

    /** null = unrestricted,array = allowed restaurant IDs */
    public static function restaurantIds(): ?array
    {
        if (app()->runningInConsole()) return null;        

        $user = auth()->user();
        if (!$user) return [];                               
        if (self::isAdmin()) return null;

        return self::$cache[$user->id] ??= match ($user->role) {
            'restaurant_owner' => DB::table('restaurants')
                ->where('owner_id', $user->id)->whereNull('deleted_at')->pluck('id')->all(),
            'kitchen_staff' => DB::table('shift_schedules')
                ->where('user_id', $user->id)->distinct()->pluck('restaurant_id')->all(),
            default => [],
        };
    }

    public static function flush(): void { self::$cache = []; }

    public static function allows(int $restaurantId): bool
    {
        $ids = self::restaurantIds();
        return $ids === null || in_array($restaurantId, $ids, true);
    }

    public static function assert(int $restaurantId): void
    {
        abort_unless(self::allows($restaurantId), 404);
    }
}
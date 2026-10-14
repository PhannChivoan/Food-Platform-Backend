<?php

namespace App\Services;

use App\Models\AccessWindow;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Turns the rows configured in /admin/settings/access-windows into an allow/deny
 * decision for the signed-in user.
 *
 * A window means "this role MAY work during these days and times". So a role that
 * has at least one active window is locked out outside of it; a role with no window
 * at all is never restricted. Several windows for the same role are a union: being
 * inside any one of them is enough.
 */
class AccessWindowService
{
    public const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    /** applies_to label => user.role values it covers */
    public const ROLE_MAP = [
        'Platform Admin' => ['super_admin', 'admin'],
        'Restaurant Owner' => ['restaurant_owner'],
        'Kitchen Staff' => ['kitchen_staff'],
        'Delivery Rider' => ['rider'],
        'Customer' => ['customer'],
        'All Staff' => ['super_admin', 'admin', 'restaurant_owner', 'kitchen_staff', 'rider'],
    ];

    /** @return array<int,string> the role codes a label covers */
    public static function rolesFor(?string $appliesTo): array
    {
        if (!$appliesTo) {
            return []; // no role selected -> the window documents itself, it gates nobody
        }
        return self::ROLE_MAP[$appliesTo] ?? [strtolower(str_replace(' ', '_', $appliesTo))];
    }

    /** @return Collection<int,AccessWindow> active windows that govern this role */
    public static function windowsFor(?string $role): Collection
    {
        if (!$role) {
            return collect();
        }

        return AccessWindow::where('is_active', true)->get()
            ->filter(fn (AccessWindow $w) => in_array($role, self::rolesFor($w->applies_to), true))
            ->values();
    }

    /**
     * Does this single window contain $at? Handles windows that run past midnight
     * (22:00 -> 02:00 counts as the window's own start day plus the next morning).
     */
    public static function windowContains(AccessWindow $window, CarbonImmutable $at): bool
    {
        $days = is_array($window->days) ? $window->days : [];
        if ($days === []) {
            return false;
        }

        $today = self::DAYS[(int) $at->format('N') - 1];
        $yesterday = self::DAYS[((int) $at->format('N') + 5) % 7];

        $start = self::minutes((string) $window->start_time);
        $end = self::minutes((string) $window->end_time);
        $now = $at->hour * 60 + $at->minute;

        if ($start === null || $end === null) {
            return false;
        }

        if ($start === $end) {
            return in_array($today, $days, true); // whole day
        }

        if ($start < $end) {
            return in_array($today, $days, true) && $now >= $start && $now < $end;
        }

        // overnight: 22:00-02:00 belongs to the day it starts
        if (in_array($today, $days, true) && $now >= $start) {
            return true;
        }
        return in_array($yesterday, $days, true) && $now < $end;
    }

    /**
     * The decision for a user. Returns is_restricted=false when the role has no
     * window configured, so an empty configuration never locks anybody out.
     */
    public static function status(?string $role, ?CarbonImmutable $at = null): array
    {
        $at ??= PlatformSettings::now();
        $windows = self::windowsFor($role);

        $payload = [
            'role' => $role,
            'is_restricted' => false,
            'is_open' => true,
            'message' => null,
            'windows' => [],
            'checked_at' => $at->toIso8601String(),
            'timezone' => PlatformSettings::timezone(),
        ];

        if ($windows->isEmpty()) {
            return $payload;
        }

        $described = $windows->map(fn (AccessWindow $w) => [
            'id' => $w->id,
            'label' => $w->label,
            'start_time' => substr((string) $w->start_time, 0, 5),
            'end_time' => substr((string) $w->end_time, 0, 5),
            'days' => is_array($w->days) ? $w->days : [],
            'applies_to' => $w->applies_to,
            'description' => $w->description,
            'is_open' => self::windowContains($w, $at),
        ])->values()->all();

        $open = collect($described)->contains('is_open', true);

        $payload['windows'] = $described;
        $payload['is_restricted'] = true;
        $payload['is_open'] = $open;
        $payload['message'] = $open
            ? null
            : 'Your role is only allowed to work inside its configured access window.';

        return $payload;
    }

    public static function statusForUser(?User $user): array
    {
        return self::status($user?->role);
    }

    private static function minutes(string $time): ?int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})/', $time, $m)) {
            return null;
        }
        return ((int) $m[1]) * 60 + (int) $m[2];
    }

    /** "Mon 08:00 - 18:00" style summary used in the admin list. */
    public static function describe(AccessWindow $window): string
    {
        $days = is_array($window->days) ? $window->days : [];
        $dayText = $days === [] ? 'No days' : (count($days) === 7 ? 'Every day' : implode(', ', $days));
        return sprintf('%s · %s - %s', $dayText, substr((string) $window->start_time, 0, 5), substr((string) $window->end_time, 0, 5));
    }
}
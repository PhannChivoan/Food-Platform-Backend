<?php

namespace App\Http\Middleware;

use App\Services\AccessWindowService;
use Closure;
use Illuminate\Http\Request;

/**
 * Blocks operational work when the signed-in role sits outside the access window
 * configured for it in /admin/settings/access-windows.
 *
 * Reading endpoints (auth, bootstrap, navigation, settings and the access-window
 * screen itself) stay reachable so the dashboard can explain WHY it is locked
 * instead of showing an empty error page.
 */
class EnforceAccessWindow
{
    /**
     * Paths that must keep working while a role is locked out, so the dashboard can
     * still load and explain the block instead of showing an empty error page:
     * sign-in, the shell itself (bootstrap/navigation/dashboard), the screens used to
     * fix the problem (access windows, settings, roles), and the read-only log.
     * Customer and rider self-service (/my, /cart, /payments) is never gated.
     */
    private const ALWAYS_ALLOWED = [
        'auth/*',
        'admin/bootstrap',
        'admin/notifications',
        'admin/notifications/read',
        'navigation-items',
        'dashboard/*',
        'access-windows',
        'access-windows/*',
        'settings',
        'settings/*',
        'roles',
        'roles/*',
        'permissions',
        'activity-logs',
        'activity-logs/*',
        'my',
        'my/*',
        'cart/*',
        'payments/*',
    ];

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user || self::isExempt($this->routePath($request))) {
            return $next($request);
        }

        $status = AccessWindowService::status($user->role);

        // unrestricted role, or inside the window: carry on
        if (!$status['is_restricted'] || $status['is_open']) {
            return $next($request);
        }

        return response()->json([
            'message' => $status['message'],
            'error' => 'outside_access_window',
            'access_window' => $status,
        ], 403);
    }

    /**
     * "menu-items", "dashboard/stats", ... — the api/v1 route prefix is stripped
     * because every route in this group carries it.
     */
    private function routePath(Request $request): string
    {
        $path = trim((string) ($request->route()?->uri() ?: $request->path()), '/');

        foreach (['api/v1/', 'api/', 'v1/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return substr($path, strlen($prefix));
            }
        }

        return $path;
    }

    /** helper used by the middleware (and handy in tests) */
    public static function isExempt(string $path): bool
    {
        $path = trim($path, '/');

        foreach (self::ALWAYS_ALLOWED as $pattern) {
            if ($pattern === $path) {
                return true;
            }
            if (str_ends_with($pattern, '/*') && str_starts_with($path, rtrim($pattern, '*'))) {
                return true;
            }
        }

        return false;
    }
}
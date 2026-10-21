<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Server-side twin of Permission Policies. For every admin page the role needs
 *   1. the page switched on (role_navigations, and the page active), and
 *   2. the matching "<page>.<action>" permission: GET = view, POST = create,
 *      PUT/PATCH = update, DELETE = delete.
 * So a page that is switched off, or an action that is unticked, is refused by the API
 * as well as hidden in the browser. Super admin is never restricted.
 *
 * RULES is ordered, the first match wins: [path regex, pages that unlock writes, pages that unlock reads].
 * Reads of shared data (customers, menu, orders...) are also unlocked by the pages that
 * display that data as a lookup, e.g. the kitchen page reads customers and menu items.
 */
class EnforceModuleAccess
{
    private const ORDER_PAGES = ['orders', 'pending-orders', 'kitchen-workflow', 'completed-orders'];
    private const MENU_PAGES = ['menu', 'menu-categories', 'menu-modifiers'];
    private const RIDER_PAGES = ['dispatch', 'delivery-tracking', 'rider-earnings', 'my-deliveries'];

    /** @return array<int, array{0:string,1:?array,2:?array}> */
    private static function rules(): array
    {
        $orderReads = array_merge(self::ORDER_PAGES, self::RIDER_PAGES, ['customer-history', 'revenue']);
        $lookup = array_merge(self::ORDER_PAGES, self::RIDER_PAGES, self::MENU_PAGES, [
            'restaurants', 'branches', 'promotions', 'redemptions', 'delivery-zones', 'revenue', 'analytics', 'all-customers',
        ]);

        return [
            // self-service calls that only share a prefix with a module
            ['#^(promotions/(validate|redeem)|users/rider-candidates|delivery-fee/calculate|access-windows/status)$#', null, null],

            ['#^settings#', ['general-settings'], null],
            ['#^users#', ['roles-permissions'], null],
            ['#^(access-windows|shift-schedules)#', ['access-windows'], null],
            ['#^activity-logs#', ['activity-log'], null],
            ['#^restaurant-categories#', ['restaurant-categories'], null],
            ['#^(zones|delivery-fee)#', ['delivery-zones'], ['delivery-zones', 'restaurants', 'branches']],
            ['#^promotions/redemptions#', ['redemptions'], null],
            ['#^promotions#', ['promotions'], null],
            ['#^loyalty#', ['loyalty'], ['loyalty', 'promotions', 'redemptions']],
            ['#^analytics/revenue#', ['revenue'], null],
            ['#^analytics/delivery#', ['delivery-performance'], null],
            ['#^analytics#', ['analytics'], null],
            ['#^exports/orders#', self::ORDER_PAGES, null],
            ['#^exports/revenue#', ['revenue'], null],

            ['#^customer-addresses#', ['customer-addresses'], ['customer-addresses', 'all-customers']],
            ['#^customers/[^/]+/addresses#', ['customer-addresses', 'all-customers'], array_merge(['customer-addresses', 'all-customers'], self::ORDER_PAGES, ['dispatch'])],
            ['#^customers/[^/]+/order-history#', ['customer-history', 'all-customers'], null],
            ['#^customers#', ['all-customers'], array_merge(['all-customers', 'customer-addresses', 'customer-history'], self::ORDER_PAGES, ['dispatch'])],

            ['#^(restaurants/[^/]+/(menu|items|categories)|branches/[^/]+/menu|menu-items)#', self::MENU_PAGES, $lookup],
            ['#^(restaurants/[^/]+/modifier-groups|modifier-groups|modifiers)#', ['menu-modifiers', 'menu'], $lookup],
            ['#^menu-categories#', ['menu-categories', 'menu'], $lookup],
            ['#^(restaurants/[^/]+/branches|restaurants/[^/]+/operating-hours|branches)#', ['branches', 'restaurants'], $lookup],
            ['#^restaurants#', ['restaurants'], $lookup],

            ['#^orders/(.*/)?(assign-rider|batch-dispatch)#', ['dispatch'], $orderReads],
            ['#^(orders|kitchen)#', self::ORDER_PAGES, $orderReads],
            ['#^(riders|dispatch|deliveries)#', self::RIDER_PAGES, array_merge(self::RIDER_PAGES, self::ORDER_PAGES)],
        ];
    }

    /** POST endpoints that change / delete an existing record, or only read, despite the verb */
    private const POST_AS_UPDATE = '#/(verify|suspend|close|refund|restore|blacklist|unblacklist|assign-rider|default|toggle|bulk-toggle|bulk-update|sync|assign-branch|copy-from-main)$|^orders/batch-dispatch$#';
    private const POST_AS_DELETE = '#/bulk-delete$#';
    private const POST_AS_VIEW = '#/export(/.*)?$#';

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user || $user->hasRole('super_admin')) {
            return $next($request);
        }

        $path = $this->path($request);
        $rule = null;
        foreach (self::rules() as $candidate) {
            if (preg_match($candidate[0], $path)) {
                $rule = $candidate;
                break;
            }
        }
        if (!$rule || $rule[1] === null) {
            return $next($request);
        }

        $action = $this->action($request, $path);
        $keys = $action === 'view' ? ($rule[2] ?? $rule[1]) : $rule[1];

        $pages = DB::table('role_navigations')
            ->join('navigation_items', 'navigation_items.id', '=', 'role_navigations.navigation_item_id')
            ->whereIn('role_navigations.role_id', $user->roles->pluck('id'))
            ->where('navigation_items.is_active', true)
            ->whereIn('navigation_items.key', $keys)
            ->pluck('navigation_items.key')
            ->all();

        if ($pages) {
            $granted = $user->getAllPermissions()->pluck('name')->all();
            foreach ($pages as $page) {
                if (in_array("$page.$action", $granted, true)) {
                    return $next($request);
                }
            }

            return $this->deny("Your role is not allowed to $action here.", 'permission_denied', $action);
        }

        return $this->deny('Your role does not have access to this module.', 'module_disabled', $action);
    }

    private function action(Request $request, string $path): string
    {
        return match ($request->method()) {
            'GET', 'HEAD', 'OPTIONS' => 'view',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'delete',
            default => preg_match(self::POST_AS_VIEW, $path) ? 'view'
                : (preg_match(self::POST_AS_DELETE, $path) ? 'delete'
                : (preg_match(self::POST_AS_UPDATE, $path) ? 'update' : 'create')),
        };
    }

    private function deny(string $message, string $error, string $action)
    {
        return response()->json(['message' => $message, 'error' => $error, 'action' => $action], 403);
    }

    private function path(Request $request): string
    {
        $path = trim((string) ($request->route()?->uri() ?: $request->path()), '/');

        foreach (['api/v1/', 'api/', 'v1/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return substr($path, strlen($prefix));
            }
        }

        return $path;
    }
}

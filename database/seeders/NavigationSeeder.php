<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The admin sidebar (navigation_items) and which role sees what (role_navigations).
 * Safe to run again: items are matched by key and only missing role links are added.
 *   php artisan db:seed --class=NavigationSeeder
 */
class NavigationSeeder extends Seeder
{
    // key, parent key, label, path, icon, is_dropdown, sort_order, is_active
    private const ITEMS = [
        ['dashboard', null, 'Dashboard', '/', 'LayoutDashboard', 0, 1, 1],
        ['operations', null, 'Operations', null, 'Workflow', 1, 2, 1],
        ['orders', 'operations', 'Orders & Kitchen', '/orders', 'ShoppingBag', 0, 1, 1],
        ['pending-orders', 'operations', 'Kitchen Queue', '/orders/pending', 'ChefHat', 0, 2, 1],
        ['kitchen-workflow', 'operations', 'Kitchen Workflow', '/kitchen', 'ClipboardList', 0, 3, 1],
        ['completed-orders', 'operations', 'Completed Orders', '/orders/completed', 'CheckCircle2', 0, 4, 1],
        ['dispatch', 'operations', 'Rider Dispatch', '/dispatch', 'Bike', 0, 5, 1],
        ['delivery-tracking', 'operations', 'Live Deliveries', '/dispatch/tracking', 'MapPinned', 0, 6, 1],
        ['rider-earnings', 'operations', 'Rider Earnings', '/dispatch/earnings', 'WalletCards', 0, 7, 1],
        ['partners', null, 'Partners', null, 'Store', 1, 3, 1],
        ['restaurants', 'partners', 'Restaurants & Branches', '/restaurants', 'Store', 0, 1, 1],
        ['branches', 'partners', 'Branch Operations', '/restaurants/branches', 'Building2', 0, 2, 1],
        ['menu', 'partners', 'Menu & Items', '/products', 'UtensilsCrossed', 0, 3, 1],
        ['menu-categories', 'partners', 'Menu Categories', '/products/categories', 'Tags', 0, 4, 1],
        ['menu-modifiers', 'partners', 'Modifier Groups', '/products/modifiers', 'ListPlus', 0, 5, 1],
        ['delivery-zones', 'partners', 'Delivery Zones', '/delivery-zones', 'Map', 0, 6, 1],
        ['customers', null, 'Customers', null, 'Users', 1, 4, 1],
        ['all-customers', 'customers', 'All Customers', '/customers', 'UserRound', 0, 1, 1],
        ['customer-addresses', 'customers', 'Delivery Addresses', '/customers/addresses', 'MapPinHouse', 0, 2, 1],
        ['customer-history', 'customers', 'Order History', '/customers/history', 'ReceiptText', 0, 3, 1],
        ['growth', null, 'Growth', null, 'Sparkles', 1, 5, 1],
        ['promotions', 'growth', 'Promotions', '/promotions', 'BadgePercent', 0, 1, 1],
        ['loyalty', 'growth', 'Loyalty Rules', '/promotions/loyalty', 'Gift', 0, 2, 1],
        ['redemptions', 'growth', 'Redemptions', '/promotions/redemptions', 'TicketCheck', 0, 3, 1],
        ['insights', null, 'Insights', null, 'ChartNoAxesCombined', 1, 6, 1],
        ['analytics', 'insights', 'Operations Analytics', '/analytics/operations', 'BarChart3', 0, 1, 1],
        ['revenue', 'insights', 'Revenue Reports', '/analytics/revenue', 'Banknote', 0, 2, 1],
        ['delivery-performance', 'insights', 'Delivery Performance', '/analytics/delivery', 'TimerReset', 0, 3, 1],
        ['activity-log', 'insights', 'Activity Log', '/activity-log', 'History', 0, 4, 1],
        ['administration', null, 'Administration', null, 'Settings2', 1, 7, 1],
        ['roles-permissions', 'administration', 'Roles & Permissions', '/settings/users', 'UserCog', 0, 1, 1],
        ['permissions', 'administration', 'Permission Policies', '/settings/permissions', 'ShieldCheck', 0, 2, 1],
        ['access-windows', 'administration', 'Access Time Windows', '/settings/access-windows', 'Clock4', 0, 3, 1],
        ['general-settings', 'administration', 'Platform Settings', '/settings', 'Settings', 0, 4, 1],
        ['my-deliveries', null, 'My Deliveries', '/driver', 'Bike', 0, 2, 1],
        ['restaurant-categories', 'administration', 'Restaurant Categories', '/settings/categories', 'Tags', 0, 5, 1],
    ];

    // role name => navigation keys it can open
    private const ROLES = [
        'admin' => ['access-windows', 'activity-log', 'administration', 'all-customers', 'analytics', 'branches', 'completed-orders', 'customer-addresses', 'customer-history', 'customers', 'dashboard', 'delivery-performance', 'delivery-tracking', 'delivery-zones', 'dispatch', 'general-settings', 'growth', 'insights', 'kitchen-workflow', 'loyalty', 'menu', 'menu-categories', 'menu-modifiers', 'my-deliveries', 'operations', 'orders', 'partners', 'pending-orders', 'permissions', 'promotions', 'redemptions', 'restaurant-categories', 'restaurants', 'revenue', 'rider-earnings', 'roles-permissions'],
        'customer' => ['completed-orders', 'dashboard', 'operations', 'orders'],
        'kitchen_staff' => ['completed-orders', 'dashboard', 'kitchen-workflow', 'operations', 'orders', 'pending-orders'],
        'restaurant_owner' => ['analytics', 'branches', 'completed-orders', 'dashboard', 'delivery-performance', 'delivery-tracking', 'growth', 'insights', 'kitchen-workflow', 'menu', 'menu-categories', 'menu-modifiers', 'operations', 'orders', 'partners', 'pending-orders', 'promotions', 'redemptions', 'restaurants', 'revenue'],
        'rider' => ['completed-orders', 'dashboard', 'delivery-tracking', 'my-deliveries', 'operations', 'orders', 'rider-earnings'],
        'super_admin' => ['access-windows', 'activity-log', 'administration', 'all-customers', 'analytics', 'branches', 'completed-orders', 'customer-addresses', 'customer-history', 'customers', 'dashboard', 'delivery-performance', 'delivery-tracking', 'delivery-zones', 'dispatch', 'general-settings', 'growth', 'insights', 'kitchen-workflow', 'loyalty', 'menu', 'menu-categories', 'menu-modifiers', 'operations', 'orders', 'partners', 'pending-orders', 'permissions', 'promotions', 'redemptions', 'restaurants', 'revenue', 'rider-earnings', 'roles-permissions'],
    ];

    public function run(): void
    {
        $now = now();
        $ids = [];

        // parents first so children can point at them
        $rows = collect(self::ITEMS)->sortBy(fn ($r) => $r[1] === null ? 0 : 1)->values();
        foreach ($rows as [$key, $parent, $label, $path, $icon, $dropdown, $sort, $active]) {
            $existing = DB::table('navigation_items')->where('key', $key)->first();
            $data = [
                'parent_id' => $parent ? ($ids[$parent] ?? null) : null,
                'label' => $label, 'path' => $path, 'icon' => $icon,
                'is_dropdown' => $dropdown, 'sort_order' => $sort, 'is_active' => $active,
                'updated_at' => $now,
            ];
            if ($existing) {
                $ids[$key] = $existing->id; // keep what is already there
            } else {
                $ids[$key] = DB::table('navigation_items')->insertGetId(['key' => $key, 'created_at' => $now] + $data);
            }
        }

        foreach (self::ROLES as $roleName => $keys) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');
            if (! $roleId) {
                continue;
            }
            foreach ($keys as $key) {
                if (! isset($ids[$key])) {
                    continue;
                }
                DB::table('role_navigations')->updateOrInsert(
                    ['role_id' => $roleId, 'navigation_item_id' => $ids[$key]],
                    ['created_at' => $now, 'updated_at' => $now],
                );
            }
        }
    }
}
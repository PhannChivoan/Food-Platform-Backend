<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AccessWindowController;
use App\Http\Controllers\Api\V1\ShiftScheduleController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\RolePermissionController;
use App\Http\Controllers\Api\V1\ActivityLogController;
use App\Http\Controllers\Api\V1\RestaurantController;
use App\Http\Controllers\Api\V1\MenuController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\DispatchController;
use App\Http\Controllers\Api\V1\ZoneController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\PromotionController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\NavigationItemsController;
use App\Http\Controllers\Api\V1\RoleNavigationController;
Route::prefix('v1')->middleware('conditional.get')->group(function () {

    // ---------- PUBLIC AUTH ROUTES ----------
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);

    // ---------- BUSINESS ONBOARDING ----------
    Route::get('/public/platform', [\App\Http\Controllers\Api\V1\PartnerController::class, 'platform']);
    // live platform settings (name, branding, currency, maintenance flag, payment methods)
    Route::get('/public/config', [\App\Http\Controllers\Api\V1\SettingsController::class, 'publicConfig']);
    Route::get('/partner/email-available', [\App\Http\Controllers\Api\V1\PartnerController::class, 'emailAvailable'])->middleware('throttle:30,1');
    Route::post('/partner/register', [\App\Http\Controllers\Api\V1\PartnerController::class, 'register']);

    // Contact form: emails the site owner (limited to 5 messages a minute per visitor)
    Route::post('/contact', [\App\Http\Controllers\Api\V1\ContactController::class, 'send'])->middleware('throttle:5,1');

    // ---------- RIDER PORTAL ----------
    Route::post('/rider/register', [\App\Http\Controllers\Api\V1\RiderPortalController::class, 'register']);
    Route::middleware(['auth:sanctum'])->prefix('rider')->group(function () {
        Route::get('/me', [\App\Http\Controllers\Api\V1\RiderPortalController::class, 'me']);
        Route::patch('/online', [\App\Http\Controllers\Api\V1\RiderPortalController::class, 'toggleOnline']);
        Route::post('/location', [\App\Http\Controllers\Api\V1\RiderPortalController::class, 'location']);
        Route::get('/deliveries', [\App\Http\Controllers\Api\V1\RiderPortalController::class, 'deliveries']);
        Route::post('/deliveries/{id}/pickup', [\App\Http\Controllers\Api\V1\RiderPortalController::class, 'pickup'])->whereNumber('id');
        Route::post('/deliveries/{id}/complete', [\App\Http\Controllers\Api\V1\RiderPortalController::class, 'complete'])->whereNumber('id');
    });

    // PayWay posts the payment result here (public; the body is re-verified with PayWay)
    Route::post('/payments/payway/callback', [\App\Http\Controllers\Api\V1\PaymentController::class, 'callback']);

    // ---------- CARTS (database; signed-in customer or anonymous X-Guest-Token) ----------
    Route::get('/cart', [\App\Http\Controllers\Api\V1\CartController::class, 'index']);
    Route::post('/cart/items', [\App\Http\Controllers\Api\V1\CartController::class, 'addItem']);
    Route::put('/cart/items/{id}', [\App\Http\Controllers\Api\V1\CartController::class, 'updateItem'])->whereNumber('id');
    Route::delete('/cart/items/{id}', [\App\Http\Controllers\Api\V1\CartController::class, 'removeItem'])->whereNumber('id');
    Route::delete('/cart/{restaurantId}', [\App\Http\Controllers\Api\V1\CartController::class, 'clear'])->whereNumber('restaurantId');

    // ---------- PUBLIC STOREFRONT ROUTES ----------
    Route::get('/public/restaurants', [\App\Http\Controllers\Api\V1\PublicRestaurantController::class, 'index']);
    Route::get('/public/cuisines', [\App\Http\Controllers\Api\V1\PublicRestaurantController::class, 'cuisines']);
    Route::get('/public/categories', [\App\Http\Controllers\Api\V1\PublicRestaurantController::class, 'categories']);
    Route::get('/public/restaurants/{id}', [\App\Http\Controllers\Api\V1\PublicRestaurantController::class, 'show'])->whereNumber('id');
    Route::get('/public/items/{id}', [\App\Http\Controllers\Api\V1\PublicRestaurantController::class, 'item'])->whereNumber('id');
    Route::get('/public/restaurants/{id}/reviews', [\App\Http\Controllers\Api\V1\ReviewController::class, 'index'])->whereNumber('id');

    // ---------- PROTECTED ROUTES (Requires Valid Token) ----------
    Route::middleware(['auth:sanctum', 'access.window', 'module.access'])->group(function () {

        // â”€â”€â”€ AUTH (Profile, Logout, Refresh) â”€â”€â”€
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/refresh', [AuthController::class, 'refresh']);
        Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
        Route::get('/auth/2fa', [\App\Http\Controllers\Api\V1\TwoFactorController::class, 'status']);
        Route::post('/auth/2fa/setup', [\App\Http\Controllers\Api\V1\TwoFactorController::class, 'setup']);
        Route::post('/auth/2fa/enable', [\App\Http\Controllers\Api\V1\TwoFactorController::class, 'enable']);
        Route::post('/auth/2fa/disable', [\App\Http\Controllers\Api\V1\TwoFactorController::class, 'disable']);

        // â”€â”€â”€ DASHBOARD (KPI Stats) â”€â”€â”€
        Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
        Route::get('/dashboard/overview', [DashboardController::class, 'overview']);

        // admin-dashboard notifications (role-aware)
        Route::get('/admin/bootstrap', [\App\Http\Controllers\Api\V1\AdminNotificationController::class, 'bootstrap']);
        Route::get('/admin/notifications', [\App\Http\Controllers\Api\V1\AdminNotificationController::class, 'index']);
        Route::post('/admin/notifications/read', [\App\Http\Controllers\Api\V1\AdminNotificationController::class, 'markRead']);

        // ─── CART: merge the guest cart at login, and checkout (needs an account) ───
        Route::post('/cart/merge', [\App\Http\Controllers\Api\V1\CartController::class, 'merge']);
        Route::post('/cart/{restaurantId}/coupon', [\App\Http\Controllers\Api\V1\CartController::class, 'coupon'])->whereNumber('restaurantId');
        Route::post('/cart/{restaurantId}/checkout', [\App\Http\Controllers\Api\V1\CartController::class, 'checkout'])->whereNumber('restaurantId');

        // ─── MY ORDERS: history, live tracking, notifications ───
        Route::get('/my/orders', [\App\Http\Controllers\Api\V1\MyOrderController::class, 'index']);
        Route::get('/my/orders/{id}', [\App\Http\Controllers\Api\V1\MyOrderController::class, 'show'])->whereNumber('id');
        Route::post('/my/orders/{id}/reorder', [\App\Http\Controllers\Api\V1\MyOrderController::class, 'reorder'])->whereNumber('id');
        Route::post('/my/orders/{id}/review', [\App\Http\Controllers\Api\V1\ReviewController::class, 'store'])->whereNumber('id');
        Route::get('/my/favorites', [\App\Http\Controllers\Api\V1\FavoriteController::class, 'index']);
        Route::post('/my/favorites/{restaurantId}', [\App\Http\Controllers\Api\V1\FavoriteController::class, 'store'])->whereNumber('restaurantId');
        Route::delete('/my/favorites/{restaurantId}', [\App\Http\Controllers\Api\V1\FavoriteController::class, 'destroy'])->whereNumber('restaurantId');
        Route::get('/my/notifications', [\App\Http\Controllers\Api\V1\MyOrderController::class, 'notifications']);
        Route::post('/my/notifications/read', [\App\Http\Controllers\Api\V1\MyOrderController::class, 'markNotificationsRead']);
        // ─── ONLINE PAYMENT (ABA PayWay) ───
        Route::post('/payments/orders/{id}/pay', [\App\Http\Controllers\Api\V1\PaymentController::class, 'pay'])->whereNumber('id');
        Route::post('/payments/orders/{id}/card', [\App\Http\Controllers\Api\V1\PaymentController::class, 'card'])->whereNumber('id');
        Route::post('/payments/orders/{id}/cancel', [\App\Http\Controllers\Api\V1\PaymentController::class, 'cancel'])->whereNumber('id');
        Route::get('/payments/orders/{id}/status', [\App\Http\Controllers\Api\V1\PaymentController::class, 'status'])->whereNumber('id');

        // ─── MY SAVED ADDRESSES (customer address book with map pin) ───
        Route::get('/my/addresses', [\App\Http\Controllers\Api\V1\MyAddressController::class, 'index']);
        Route::post('/my/addresses', [\App\Http\Controllers\Api\V1\MyAddressController::class, 'store']);
        Route::put('/my/addresses/{id}', [\App\Http\Controllers\Api\V1\MyAddressController::class, 'update'])->whereNumber('id');
        Route::put('/my/addresses/{id}/default', [\App\Http\Controllers\Api\V1\MyAddressController::class, 'setDefault'])->whereNumber('id');
        Route::delete('/my/addresses/{id}', [\App\Http\Controllers\Api\V1\MyAddressController::class, 'destroy'])->whereNumber('id');

        // USER MANAGEMENT
        Route::get('/users/rider-candidates', [DispatchController::class, 'riderCandidates']);
        Route::apiResource('/users', UserController::class);

        // â”€â”€â”€ MODULE 1: ROLE & PERMISSION (Super Admin only) â”€â”€â”€
        // restaurant categories shown to customers (platform admin)
        Route::middleware(['role:super_admin|admin'])->group(function () {
            Route::get('/restaurant-categories', [\App\Http\Controllers\Api\V1\RestaurantCategoryController::class, 'index']);
            Route::post('/restaurant-categories', [\App\Http\Controllers\Api\V1\RestaurantCategoryController::class, 'store']);
            Route::put('/restaurant-categories/{id}', [\App\Http\Controllers\Api\V1\RestaurantCategoryController::class, 'update'])->whereNumber('id');
            Route::delete('/restaurant-categories/{id}', [\App\Http\Controllers\Api\V1\RestaurantCategoryController::class, 'destroy'])->whereNumber('id');
        });

        Route::middleware(['role:super_admin'])->group(function () {
            Route::apiResource('/roles', RolePermissionController::class);
            Route::get('/permissions', [RolePermissionController::class, 'permissions']);
            Route::post('/roles/{role}/permissions', [RolePermissionController::class, 'assignPermission']);
            Route::post('/roles/{role}/permissions/sync', [RolePermissionController::class, 'syncPermissions']);
            Route::delete('/roles/{role}/permissions/{permission}', [RolePermissionController::class, 'revokePermission']);
            Route::get('/audit-trail', [RolePermissionController::class, 'auditTrail']);
        });

        // â”€â”€â”€ MODULE 2: ACTIVITY LOG â”€â”€â”€
        Route::get('/activity-logs', [ActivityLogController::class, 'index']);
        Route::post('/activity-logs/export', [ActivityLogController::class, 'export']);
        Route::get('/activity-logs/filters', [ActivityLogController::class, 'filters']);

        // PLATFORM SETTINGS
        Route::get('/settings', [SettingsController::class, 'index']);
        Route::put('/settings', [SettingsController::class, 'update']);

        // â”€â”€â”€ MODULE 3: RESTAURANT & BRANCH â”€â”€â”€
        Route::get('/branches', [RestaurantController::class, 'branches']);
        Route::apiResource('/restaurants', RestaurantController::class);
        Route::post('/restaurants/{restaurant}/verify', [RestaurantController::class, 'verify']);
        Route::post('/restaurants/{restaurant}/suspend', [RestaurantController::class, 'suspend']);
        Route::post('/restaurants/{restaurant}/close', [RestaurantController::class, 'close']);
        Route::post('/restaurants/{restaurant}/branches', [RestaurantController::class, 'addBranch']);
        Route::put('/restaurants/{restaurant}/branches/{branch}', [RestaurantController::class, 'updateBranch']);
        Route::delete('/restaurants/{restaurant}/branches/{branch}', [RestaurantController::class, 'deleteBranch']);
        Route::get('/restaurants/{restaurant}/operating-hours', [RestaurantController::class, 'getOperatingHours']);
        Route::put('/restaurants/{restaurant}/operating-hours', [RestaurantController::class, 'updateOperatingHours']);

        // â”€â”€â”€ MODULE 4: MENU & ITEM â”€â”€â”€
        Route::post('/branches/{branch}/menu/copy-from-main', [MenuController::class, 'copyMenuFromMain'])->whereNumber('branch');
        Route::get('/menu-categories', [MenuController::class, 'categoriesIndex']);
        Route::get('/menu-categories/{restaurant}/{category}', [MenuController::class, 'categoriesShow']);
        Route::get('/menu-items', [MenuController::class, 'itemsIndex']);
        Route::get('/restaurants/{restaurant}/menu', [MenuController::class, 'index']);
        Route::post('/restaurants/{restaurant}/categories', [MenuController::class, 'storeCategory']);
        Route::put('/restaurants/{restaurant}/categories/{category}', [MenuController::class, 'updateCategory']);
        Route::delete('/restaurants/{restaurant}/categories/{category}', [MenuController::class, 'deleteCategory']);
        Route::post('/restaurants/{restaurant}/items', [MenuController::class, 'storeItem']);
        Route::put('/restaurants/{restaurant}/items/{item}', [MenuController::class, 'updateItem']);
        Route::delete('/restaurants/{restaurant}/items/{item}', [MenuController::class, 'deleteItem']);
        Route::patch('/restaurants/{restaurant}/items/{item}/toggle', [MenuController::class, 'toggleAvailability']);
        Route::post('/restaurants/{restaurant}/items/bulk-toggle', [MenuController::class, 'bulkToggleAvailability']);
        Route::post('/restaurants/{restaurant}/items/bulk-update', [MenuController::class, 'bulkUpdate']);
        Route::post('/restaurants/{restaurant}/items/bulk-delete', [MenuController::class, 'bulkDelete']);
        Route::post('/restaurants/{restaurant}/menu/import', [MenuController::class, 'import']);
        Route::get('/restaurants/{restaurant}/menu/export', [MenuController::class, 'export']);
        Route::get('/restaurants/{restaurant}/modifier-groups', [MenuController::class, 'getModifierGroups']);
        Route::post('/restaurants/{restaurant}/modifier-groups', [MenuController::class, 'storeModifierGroup']);
        Route::get('/modifier-groups', [MenuController::class, 'modifierGroupsIndex']);
        Route::get('/modifier-groups/{group}', [MenuController::class, 'modifierGroupsShow']);
        Route::post('/modifier-groups/{group}/modifiers', [MenuController::class, 'storeModifier']);
        Route::put('/modifier-groups/{group}', [MenuController::class, 'updateModifierGroup']);
        Route::delete('/modifier-groups/{group}', [MenuController::class, 'deleteModifierGroup']);
        Route::put('/modifiers/{modifier}', [MenuController::class, 'updateModifier']);
        Route::delete('/modifiers/{modifier}', [MenuController::class, 'deleteModifier']);

        // â”€â”€â”€ MODULE 5: ORDER & KITCHEN â”€â”€â”€
        // Static routes BEFORE apiResource to avoid shadowing
        Route::get('/orders/queue', [OrderController::class, 'queue']);
        Route::get('/orders/completed', [OrderController::class, 'completed']);
        Route::apiResource('/orders', OrderController::class);
        Route::patch('/orders/{order}/accept', [OrderController::class, 'accept']);
        Route::patch('/orders/{order}/reject', [OrderController::class, 'reject']);
        Route::patch('/orders/{order}/status', [OrderController::class, 'updateStatus']);
        Route::patch('/orders/{order}/prepare', [OrderController::class, 'startPreparing']);
        Route::patch('/orders/{order}/ready', [OrderController::class, 'markReady']);
        Route::patch('/orders/{order}/cancel', [OrderController::class, 'cancel']);
        Route::patch('/orders/{order}/picked-up', [OrderController::class, 'markPickedUp']);
        Route::post('/orders/{order}/refund', [OrderController::class, 'refund']);
        Route::get('/orders/{order}/timeline', [OrderController::class, 'timeline']);
        Route::get('/kitchen/metrics', [OrderController::class, 'kitchenMetrics']);

        // â”€â”€â”€ MODULE 6: RIDER & DISPATCH â”€â”€â”€
        // Static routes BEFORE apiResource to avoid shadowing
        Route::get('/dispatch/dashboard', [DispatchController::class, 'dashboard']);
        Route::get('/dispatch/tracking', [DispatchController::class, 'tracking']);
        Route::get('/dispatch/earnings', [DispatchController::class, 'earningsDashboard']);
        Route::get('/riders/available', [DispatchController::class, 'availableRiders']);
        Route::get('/deliveries/tracking', [DispatchController::class, 'tracking']);
        Route::apiResource('/riders', DispatchController::class);
        Route::patch('/riders/{rider}/online', [DispatchController::class, 'toggleOnline']);
        Route::post('/orders/{order}/assign-rider', [DispatchController::class, 'assignRider']);
        Route::patch('/deliveries/{assignment}/status', [DispatchController::class, 'updateDeliveryStatus']);
        Route::delete('/deliveries/{assignment}', [DispatchController::class, 'destroyAssignment']);
        Route::get('/deliveries/{assignment}/track', [DispatchController::class, 'track']);
        Route::get('/riders/{rider}/earnings', [DispatchController::class, 'earnings']);
        Route::get('/riders/{rider}/performance', [DispatchController::class, 'performance']);
        Route::post('/orders/batch-dispatch', [DispatchController::class, 'batchDispatch']);

        // â”€â”€â”€ MODULE 7: DELIVERY ZONE & FEE â”€â”€â”€
        Route::apiResource('/zones', ZoneController::class);
        Route::get('/zones/{zone}/branches', [ZoneController::class, 'branches']);
        Route::post('/zones/{zone}/assign-branch', [ZoneController::class, 'assignBranch']);
        Route::delete('/zones/{zone}/branches/{branch}', [ZoneController::class, 'removeBranch']);
        Route::get('/zones/{zone}/surge', [ZoneController::class, 'getSurgeRules']);
        Route::put('/zones/{zone}/surge', [ZoneController::class, 'updateSurgeRules']);
        Route::post('/delivery-fee/calculate', [ZoneController::class, 'calculateFee']);

        // â”€â”€â”€ MODULE 8: CUSTOMER & ADDRESS â”€â”€â”€
        // Static routes BEFORE apiResource to avoid shadowing
        Route::get('/customer-addresses', [CustomerController::class, 'allAddresses']);
        Route::get('/customer-addresses/{address}', [CustomerController::class, 'allAddressesShow']);
        Route::get('/customers/export/csv', [CustomerController::class, 'export']);
        Route::apiResource('/customers', CustomerController::class);
        Route::post('/customers/{customer}/restore', [CustomerController::class, 'restore']);
        Route::delete('/customers/{customer}/force-delete', [CustomerController::class, 'forceDelete']);
        Route::get('/customers/{customer}/addresses', [CustomerController::class, 'addresses']);
        Route::post('/customers/{customer}/addresses', [CustomerController::class, 'storeAddress']);
        Route::put('/customers/{customer}/addresses/{address}', [CustomerController::class, 'updateAddress']);
        Route::delete('/customers/{customer}/addresses/{address}', [CustomerController::class, 'deleteAddress']);
        Route::post('/customers/{customer}/addresses/{address}/default', [CustomerController::class, 'setDefaultAddress']);
        Route::post('/customers/{customer}/blacklist', [CustomerController::class, 'blacklist']);
        Route::post('/customers/{customer}/unblacklist', [CustomerController::class, 'unblacklist']);
        Route::get('/customers/{customer}/order-history', [CustomerController::class, 'orderHistory']);
        Route::get('/customers/{customer}/loyalty', [CustomerController::class, 'loyaltyBalance']);

        // â”€â”€â”€ MODULE 9: PROMOTION & LOYALTY â”€â”€â”€
        Route::post('/promotions/validate', [PromotionController::class, 'validateCode']);
        Route::post('/promotions/redeem', [PromotionController::class, 'redeem']);
        Route::get('/promotions/redemptions', [PromotionController::class, 'redemptionAnalytics']);
        Route::post('/promotions/generate-codes', [PromotionController::class, 'generateCodes']);

        // ---------- MODULE 11: SHIFT SCHEDULES ----------
        Route::get('/shift-schedules', [ShiftScheduleController::class, 'index']);
        Route::post('/shift-schedules', [ShiftScheduleController::class, 'store']);
        Route::get('/shift-schedules/{id}', [ShiftScheduleController::class, 'show']);
        Route::put('/shift-schedules/{id}', [ShiftScheduleController::class, 'update']);
        Route::delete('/shift-schedules/{id}', [ShiftScheduleController::class, 'destroy']);

        // ---------- MODULE 12: ACCESS WINDOWS ----------
        // what the signed-in role is allowed to do right now (drives the lockout screen)
        Route::get('/access-windows/status', [AccessWindowController::class, 'status']);
        Route::apiResource('/access-windows', AccessWindowController::class);
        Route::patch('/access-windows/{accessWindow}/toggle', [AccessWindowController::class, 'toggle']);
        Route::post('/promotions/bulk-update', [PromotionController::class, 'bulkUpdate']);
        Route::post('/promotions/bulk-delete', [PromotionController::class, 'bulkDelete']);
        Route::apiResource('/promotions', PromotionController::class);
        Route::get('/exports/orders', [\App\Http\Controllers\Api\V1\ExportController::class, 'orders']);
        Route::get('/exports/revenue', [\App\Http\Controllers\Api\V1\ExportController::class, 'revenue']);
        Route::get('/loyalty/rules', [PromotionController::class, 'getLoyaltyRules']);
        Route::put('/loyalty/rules', [PromotionController::class, 'updateLoyaltyRules']);
        Route::get('/loyalty/transactions', [PromotionController::class, 'loyaltyTransactions']);

        // â”€â”€â”€ MODULE 10: OPERATIONS ANALYTICS â”€â”€â”€
        Route::get('/analytics/heatmap', [AnalyticsController::class, 'orderHeatmap']);
        Route::get('/analytics/sla', [AnalyticsController::class, 'slaPerformance']);
        Route::get('/analytics/delivery-performance', [AnalyticsController::class, 'deliveryPerformance']);
        Route::get('/analytics/revenue-split', [AnalyticsController::class, 'revenueSplit']);

        Route::apiResource('navigation-items', NavigationItemsController::class)->only(['index']);
        Route::get('/roles/{role}/navigation', [RoleNavigationController::class, 'show']);
        Route::put('/roles/{role}/navigation', [RoleNavigationController::class, 'update']);

        Route::middleware(['role:super_admin'])->group(function () {
            Route::apiResource('navigation-items', NavigationItemsController::class)->except(['index']);
        });
    });
});


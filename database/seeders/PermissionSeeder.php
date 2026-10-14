<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class PermissionSeeder extends Seeder
{
    public function run()
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $guard = 'web';

        $permissions = [
            'view-restaurants', 'create-restaurants', 'edit-restaurants', 'delete-restaurants', 'verify-restaurants',
            'view-menus', 'create-menus', 'edit-menus', 'delete-menus', 'toggle-availability',
            'view-orders', 'create-orders', 'accept-orders', 'reject-orders', 'update-order-status',
            'view-riders', 'assign-riders', 'track-deliveries', 'view-earnings',
            'view-zones', 'create-zones', 'edit-zones', 'delete-zones', 'manage-surge',
            'view-customers', 'blacklist-customers', 'view-loyalty',
            'view-promotions', 'create-promotions', 'edit-promotions', 'delete-promotions',
            'view-analytics', 'export-reports',
            'view-logs', 'export-logs',
            'manage-shifts',
            'manage-users', 'manage-roles', 'manage-permissions',
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => $guard]);
        }

        $superAdmin = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => $guard]);
        $superAdmin->syncPermissions(Permission::all());

        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => $guard]);
        $admin->syncPermissions(Permission::all());

        $restaurantOwner = Role::firstOrCreate(['name' => 'restaurant_owner', 'guard_name' => $guard]);
        $restaurantOwner->syncPermissions([
            'view-restaurants', 'edit-restaurants',
            'view-menus', 'create-menus', 'edit-menus', 'delete-menus', 'toggle-availability',
            'view-orders', 'accept-orders', 'reject-orders', 'update-order-status',
            'view-promotions', 'create-promotions', 'edit-promotions', 'delete-promotions',
            'view-analytics',
        ]);

        $kitchenStaff = Role::firstOrCreate(['name' => 'kitchen_staff', 'guard_name' => $guard]);
        $kitchenStaff->syncPermissions([
            'view-orders', 'accept-orders', 'reject-orders', 'update-order-status',
        ]);

        $rider = Role::firstOrCreate(['name' => 'rider', 'guard_name' => $guard]);
        $rider->syncPermissions([
            'view-orders', 'track-deliveries', 'view-earnings',
        ]);

        $customer = Role::firstOrCreate(['name' => 'customer', 'guard_name' => $guard]);
        $customer->syncPermissions([
            'view-orders', 'create-orders',
        ]);
    }
}

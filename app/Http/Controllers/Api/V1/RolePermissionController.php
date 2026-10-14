<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\ActivityLog;
use App\Models\User;

class RolePermissionController extends Controller
{
    public function index()
    {
        $roles = Role::with('permissions')->get()->map(function ($role) {
            return [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->map(fn ($p) => $p->name),
                'users_count' => self::userCount($role),
            ];
        });

        return response()->json($roles);
    }

    public function store(Request $request)
    {
        $request->validate(['name' => 'required|string|unique:roles']);
        $role = Role::create(['name' => $request->name, 'guard_name' => 'web']);
        return response()->json([
            'id' => $role->id,
            'name' => $role->name,
            'permissions' => [],
            'users_count' => 0,
        ], 201);
    }

    public function show(Role $role)
    {
        $role->load('permissions');
        return response()->json([
            'id' => $role->id,
            'name' => $role->name,
            'permissions' => $role->permissions->map(fn ($p) => $p->name),
            'users_count' => self::userCount($role),
        ]);
    }

    public function update(Request $request, Role $role)
    {
        $request->validate(['name' => 'required|string|unique:roles,name,' . $role->id]);
        $role->update(['name' => $request->name]);
        return response()->json([
            'id' => $role->id,
            'name' => $role->name,
            'permissions' => $role->permissions->map(fn ($p) => $p->name),
            'users_count' => self::userCount($role),
        ]);
    }

    public function destroy(Role $role)
    {
        $role->delete();
        return response()->json(['message' => 'Role deleted.']);
    }

    public function permissions()
    {
        $permissions = Permission::all()->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'description' => $p->description ?? '',
            'group' => self::groupFor($p->name),
        ]);

        return response()->json($permissions);
    }

    public function assignPermission(Request $request, Role $role)
    {
        $request->validate(['permission' => 'required|string']);
        $permission = Permission::where('name', $request->permission)->first();
        if (!$permission) {
            return response()->json(['message' => 'Permission not found.'], 404);
        }
        $role->givePermissionTo($permission);
        return response()->json(['message' => 'Permission assigned.']);
    }

    public function revokePermission(Role $role, $permissionName)
    {
        $permission = Permission::where('name', $permissionName)->first();
        if (!$permission) {
            return response()->json(['message' => 'Permission not found.'], 404);
        }
        $role->revokePermissionTo($permission);
        return response()->json(['message' => 'Permission revoked.']);
    }

    public function syncPermissions(Request $request, Role $role)
    {
        $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'required|string',
        ]);

        $permNames = $request->permissions;
        $validPerms = Permission::whereIn('name', $permNames)->pluck('name')->toArray();
        $role->syncPermissions($validPerms);

        return response()->json([
            'message' => 'Permissions synced.',
            'id' => $role->id,
            'name' => $role->name,
            'permissions' => $role->fresh()->permissions->map(fn ($p) => $p->name),
        ]);
    }

    public function auditTrail()
    {
        $logs = ActivityLog::with('user')
            ->orderBy('created_at', 'desc')
            ->paginate(20);
        return response()->json($logs);
    }

    private static function userCount(Role $role): int
    {
        return DB::table('model_has_roles')
            ->where('role_id', $role->id)
            ->where('model_type', User::class)
            ->count();
    }

    private static function groupFor(string $name): string
    {
        $map = [
            'dashboard' => 'Dashboard',
            'restaurant' => 'Restaurants',
            'branch' => 'Restaurants',
            'menu' => 'Menu',
            'item' => 'Menu',
            'modifier' => 'Menu',
            'category' => 'Menu',
            'order' => 'Orders',
            'kitchen' => 'Orders',
            'rider' => 'Riders',
            'dispatch' => 'Riders',
            'delivery' => 'Riders',
            'customer' => 'Customers',
            'address' => 'Customers',
            'promotion' => 'Promotions',
            'loyalty' => 'Promotions',
            'zone' => 'Delivery Zones',
            'analytics' => 'Analytics',
            'report' => 'Analytics',
            'user' => 'Users & Roles',
            'role' => 'Users & Roles',
            'permission' => 'Users & Roles',
            'setting' => 'Settings',
            'activity' => 'Activity Log',
        ];

        $lower = strtolower($name);
        foreach ($map as $key => $group) {
            if (str_contains($lower, $key)) return $group;
        }
        return 'Other';
    }
}
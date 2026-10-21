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
        ActivityLog::record('role_created', $role, ['name' => $role->name]);
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
        $before = $role->name;
        $role->update(['name' => $request->name]);
        ActivityLog::record('role_renamed', $role, [], $before, $role->name);
        return response()->json([
            'id' => $role->id,
            'name' => $role->name,
            'permissions' => $role->permissions->map(fn ($p) => $p->name),
            'users_count' => self::userCount($role),
        ]);
    }

    public function destroy(Role $role)
    {
        ActivityLog::record('role_deleted', $role, ['name' => $role->name]);
        $role->delete();
        return response()->json(['message' => 'Role deleted.']);
    }

    public function permissions()
    {
        $permissions = Permission::all()->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'description' => $p->description ?? self::moduleDescription($p->name),
            'group' => self::moduleLabels()[explode('.', $p->name)[0]] ?? self::groupFor($p->name),
            'module' => str_contains($p->name, '.'),
        ])->sortBy([['module', 'desc'], ['group', 'asc']])->values();

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
        ActivityLog::record('permission_granted', $role, ['role' => $role->name, 'permission' => $permission->name]);
        return response()->json(['message' => 'Permission assigned.']);
    }

    public function revokePermission(Role $role, $permissionName)
    {
        $permission = Permission::where('name', $permissionName)->first();
        if (!$permission) {
            return response()->json(['message' => 'Permission not found.'], 404);
        }
        $role->revokePermissionTo($permission);
        ActivityLog::record('permission_revoked', $role, ['role' => $role->name, 'permission' => $permission->name]);
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
        $before = $role->permissions()->pluck('name')->all();
        $role->syncPermissions($validPerms);
        $added = array_values(array_diff($validPerms, $before));
        $removed = array_values(array_diff($before, $validPerms));
        if ($added || $removed) {
            ActivityLog::record('permissions_changed', $role, ['role' => $role->name, 'granted' => $added, 'revoked' => $removed]);
        }

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

    /** navigation key => page label, used to group the "<page>.<action>" permissions */
    private static function moduleLabels(): array
    {
        return once(fn () => DB::table('navigation_items')->whereNotNull('path')->pluck('label', 'key')->all());
    }

    private static function moduleDescription(string $name): string
    {
        if (!str_contains($name, '.')) return '';
        [$key, $action] = explode('.', $name, 2);
        $label = self::moduleLabels()[$key] ?? $key;

        return match ($action) {
            'view' => "Open and read $label",
            'create' => "Add new records in $label",
            'update' => "Edit and change status of records in $label",
            'delete' => "Delete records in $label",
            default => '',
        };
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
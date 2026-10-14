<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Mail\InviteUserMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    private const AVATAR_DISK = 'public';
    private const AVATAR_DIR = 'users/avatars';

    private const ROLE_MAP = [
        'Platform Admin' => 'admin',
        'Restaurant Owner' => 'restaurant_owner',
        'Kitchen Staff' => 'kitchen_staff',
        'Delivery Rider' => 'rider',
        'Customer' => 'customer',
    ];

    private static function mapRole(?string $role): ?string
    {
        if (!$role) return null;
        return self::ROLE_MAP[$role] ?? $role;
    }

    private static function displayRole(?string $role): string
    {
        $labels = [
            'super_admin' => 'Platform Admin',
            'admin' => 'Platform Admin',
            'restaurant_owner' => 'Restaurant Owner',
            'kitchen_staff' => 'Kitchen Staff',
            'rider' => 'Delivery Rider',
            'customer' => 'Customer',
        ];
        return $labels[$role] ?? ucfirst(str_replace('_', ' ', $role));
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:200',
            'search' => 'nullable|string|max:100',
            'role' => 'nullable|string|max:50',
            'status' => 'nullable|string|in:active,invited,suspended',
            'type' => 'nullable|string|in:staff,customer',
        ]);

        $limit = $validated['limit'] ?? 15;
        $page = $validated['page'] ?? 1;
        $search = trim((string) ($validated['search'] ?? ''));
        $role = $validated['role'] ?? null;
        $status = $validated['status'] ?? null;
        $type = $validated['type'] ?? null;

        $query = User::query()->with('restaurant:id,name');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($role) {
            $query->where('role', self::mapRole($role));
        }

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'suspended') {
            $query->where('is_active', false);
        }

        if ($type === 'staff') {
            $query->where('role', '!=', 'customer');
        } elseif ($type === 'customer') {
            $query->where('role', 'customer');
        }

        $total = $query->count();
        $users = $query->orderByDesc('created_at')
            ->skip(($page - 1) * $limit)
            ->take($limit)
            ->get()
            ->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'avatar' => $user->avatar,
                'avatar_url' => $user->avatar_url,
                'role' => self::displayRole($user->role),
                'user_type' => $user->role === 'customer' ? 'customer' : 'staff',
                'scope' => $user->restaurant?->name ?? '-',
                'is_active' => $user->is_active,
                'status' => $user->is_active ? 'active' : 'suspended',
                'last_active' => $user->last_login_at?->toISOString(),
                'created_at' => $user->created_at?->toISOString(),
                'updated_at' => $user->updated_at?->toISOString(),
            ]);

        $stats = [
            'total' => User::count(),
            'active' => User::where('is_active', true)->count(),
            'total_admins' => User::where('role', 'admin')->orWhere('role', 'super_admin')->count(),
            'total_staff' => User::where('role', '!=', 'customer')->count(),
            'total_customers' => User::where('role', 'customer')->count(),
            'roles' => User::distinct('role')->count('role'),
            'invited' => 0,
        ];

        return response()->json([
            'data' => $users,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'stats' => $stats,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:50',
            'role' => 'required|string|max:50',
            'avatar' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'is_active' => 'nullable|boolean',
        ]);

        $avatarPath = null;
        if ($request->hasFile('avatar')) {
            $avatarPath = $request->file('avatar')->store(self::AVATAR_DIR, self::AVATAR_DISK);
        }

        $plainPassword = $validated['password'] ?? strtoupper(uniqid('PWD', true));

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => Hash::make($plainPassword),
            'role' => self::mapRole($validated['role']),
            'avatar' => $avatarPath,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        $emailSent = false;
        try {
            Mail::to($user->email)->send(new InviteUserMail($user, $plainPassword));
            $emailSent = true;
        } catch (\Exception $e) {
            \Log::warning('Failed to send invitation email to ' . $user->email . ': ' . $e->getMessage());
        }

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'avatar' => $user->avatar,
            'avatar_url' => $user->avatar_url,
            'role' => self::displayRole($user->role),
            'user_type' => $user->role === 'customer' ? 'customer' : 'staff',
            'is_active' => $user->is_active,
            'status' => $user->is_active ? 'active' : 'suspended',
            'created_at' => $user->created_at?->toISOString(),
            'email_sent' => $emailSent,
            'password' => $plainPassword,
        ], 201);
    }

    public function show(User $user)
    {
        $user->load('restaurant:id,name');

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'avatar' => $user->avatar,
            'avatar_url' => $user->avatar_url,
            'role' => self::displayRole($user->role),
            'user_type' => $user->role === 'customer' ? 'customer' : 'staff',
            'scope' => $user->restaurant?->name ?? '-',
            'is_active' => $user->is_active,
            'status' => $user->is_active ? 'active' : 'suspended',
            'last_active' => $user->last_login_at?->toISOString(),
            'created_at' => $user->created_at?->toISOString(),
            'updated_at' => $user->updated_at?->toISOString(),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|max:50',
            'role' => 'sometimes|string|max:50',
            'avatar' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'remove_avatar' => 'nullable|boolean',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($request->hasFile('avatar')) {
            $this->deleteAvatar($user->avatar);
            $user->avatar = $request->file('avatar')->store(self::AVATAR_DIR, self::AVATAR_DISK);
        } elseif (!empty($validated['remove_avatar'])) {
            $this->deleteAvatar($user->avatar);
            $user->avatar = null;
        }

        if (isset($validated['name'])) $user->name = $validated['name'];
        if (isset($validated['email'])) $user->email = $validated['email'];
        if (array_key_exists('phone', $validated)) $user->phone = $validated['phone'];
        if (isset($validated['role'])) $user->role = self::mapRole($validated['role']);
        if (isset($validated['is_active'])) $user->is_active = $validated['is_active'];

        $user->save();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'avatar' => $user->avatar,
            'avatar_url' => $user->avatar_url,
            'role' => self::displayRole($user->role),
            'is_active' => $user->is_active,
            'status' => $user->is_active ? 'active' : 'suspended',
            'updated_at' => $user->updated_at?->toISOString(),
        ]);
    }

    public function destroy(User $user)
    {
        $this->deleteAvatar($user->avatar);
        $user->delete();

        return response()->json(['message' => 'User deleted.']);
    }

    private function deleteAvatar(?string $path): void
    {
        if ($path && Storage::disk(self::AVATAR_DISK)->exists($path)) {
            Storage::disk(self::AVATAR_DISK)->delete($path);
        }
    }
}

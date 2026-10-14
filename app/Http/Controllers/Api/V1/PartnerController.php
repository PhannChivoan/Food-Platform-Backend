<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Commission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Business onboarding: a restaurant signs itself up, then manages everything from the admin dashboard. */
class PartnerController extends Controller
{
    /** Public facts the landing pages quote (fee model, platform name). */
    public function platform()
    {
        return response()->json([
            'name' => DB::table('platform_settings')->where('key', 'platform_name')->value('value') ?? 'FoodOrder Platform',
            'support_email' => DB::table('platform_settings')->where('key', 'support_email')->value('value'),
            'commission_rate' => Commission::platformRate(),
            'subscription_fee' => 0,
        ]);
    }

    /** Lets the sign-up wizard reject a taken email before the owner fills in the rest. */
    public function emailAvailable(Request $request)
    {
        $v = $request->validate(['email' => 'required|string|email|max:255']);
        return response()->json(['available' => !DB::table('users')->where('email', $v['email'])->exists()]);
    }

    /**
     * Creates the owner account (role restaurant_owner), the restaurant (pending until an admin verifies it)
     * and its first branch, and signs the owner in so they can open the admin dashboard straight away.
     */
    public function register(Request $request)
    {
        $v = $request->validate([
            'owner_name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'phone' => 'required|string|max:20',
            'password' => 'required|string|min:8|confirmed',
            'restaurant_name' => 'required|string|max:255',
            'category_id' => 'required|integer|exists:restaurant_categories,id',
            'description' => 'nullable|string|max:2000',
            'address' => 'required|string|max:255',
            'city' => 'nullable|string|max:100',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'accept_terms' => 'accepted',
        ]);

        $result = DB::transaction(function () use ($v) {
            $user = User::create([
                'name' => $v['owner_name'],
                'email' => $v['email'],
                'phone' => $v['phone'],
                'password' => Hash::make($v['password']),
                'role' => 'restaurant_owner',
                'is_active' => true,
            ]);
            $user->assignRole('restaurant_owner');

            $slug = Str::slug($v['restaurant_name']);
            $base = $slug;
            for ($i = 2; DB::table('restaurants')->where('slug', $slug)->exists(); $i++) {
                $slug = "{$base}-{$i}";
            }

            $restaurantId = DB::table('restaurants')->insertGetId([
                'owner_id' => $user->id,
                'name' => $v['restaurant_name'],
                'slug' => $slug,
                'description' => $v['description'] ?? null,
                'category_id' => $v['category_id'],
                'cuisine_type' => DB::table('restaurant_categories')->where('id', $v['category_id'])->value('name'),
                'status' => 'pending', // customers see it once an admin verifies it
                // the commission accepted at sign-up is kept with the restaurant
                'settings' => json_encode(['commission_rate' => Commission::platformRate(), 'terms_accepted_at' => now()->toIso8601String()]),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $lat = (float) $v['latitude'];
            $lng = (float) $v['longitude'];
            DB::table('branches')->insert([
                'restaurant_id' => $restaurantId,
                'name' => 'Main Branch',
                'address' => $v['address'],
                'city' => $v['city'] ?? '',
                'location' => DB::raw("ST_GeomFromText('POINT({$lng} {$lat})')"),
                'phone' => $v['phone'],
                'operating_hours' => json_encode(['monday' => '08:00-22:00', 'tuesday' => '08:00-22:00', 'wednesday' => '08:00-22:00', 'thursday' => '08:00-22:00', 'friday' => '08:00-22:00', 'saturday' => '08:00-22:00', 'sunday' => '08:00-22:00']),
                'is_active' => true,
                'is_main' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return [$user, $restaurantId];
        });

        [$user, $restaurantId] = $result;

        return response()->json([
            'message' => 'Your restaurant was registered. Sign in to the dashboard to set up your menu.',
            'user' => $user->only(['id', 'name', 'email', 'role']),
            'restaurant' => ['id' => $restaurantId, 'name' => $v['restaurant_name'], 'status' => 'pending'],
            'token' => $user->createToken('auth_token')->plainTextToken,
        ], 201);
    }
}
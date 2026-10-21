<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\HasApiTokens;

class AuthController extends Controller
{
    /**
     * Register a new user.
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'phone'    => 'nullable|string|max:20',
            'role'     => 'sometimes|in:customer,restaurant_owner,rider,kitchen_staff',
        ]);

        $user = User::create([
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'password' => Hash::make($validated['password']),
            'phone'    => $validated['phone'] ?? null,
            'role'     => $validated['role'] ?? 'customer',
            'is_active'=> true,
        ]);

        // Assign the default role (customer) – Spatie will handle this
        $user->assignRole($user->role);

        // Create a Sanctum token
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'User registered successfully',
            'user'    => $user->only(['id', 'name', 'email', 'role']),
            'token'   => $token,
        ], 201);
    }

    /**
     * Login user and issue token.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
            'otp'      => 'nullable|string|max:12',
        ]);

        // Throttle login attempts: 5 attempts per minute per email/IP
        $key = 'login_attempts_' . ($request->email ?: $request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => ['Too many login attempts. Please try again later.'],
            ]);
        }

        $user = User::where('email', $request->email)->first();

        // account lockout: too many wrong passwords / codes in a row lock the account for a while
        if ($user && $user->locked_until && $user->locked_until->isFuture()) {
            $minutes = (int) ceil(now()->diffInSeconds($user->locked_until, true) / 60);
            return response()->json([
                'message' => "This account is locked after too many failed sign-ins. Try again in {$minutes} minute(s).",
                'error' => 'account_locked',
            ], 423);
        }

        if (!$user || !Hash::check($request->password, $user->password)) {
            RateLimiter::hit($key, 60);
            $this->registerFailure($user);
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // two-factor: the password is right, now ask for the authenticator code
        if ($user->two_factor_enabled) {
            if (!$request->filled('otp')) {
                return response()->json([
                    'message' => 'Enter the 6-digit code from your authenticator app.',
                    'two_factor_required' => true,
                ], 422);
            }
            $secret = \Illuminate\Support\Facades\Crypt::decryptString($user->two_factor_secret);
            if (!\App\Services\TwoFactor::verify($secret, $request->otp)) {
                RateLimiter::hit($key, 60);
                $this->registerFailure($user);
                return response()->json([
                    'message' => 'That code is not correct.',
                    'two_factor_required' => true,
                ], 422);
            }
        }

        $user->forceFill(['failed_logins' => 0, 'locked_until' => null])->save();

        // Clear rate limiter on successful login
        RateLimiter::clear($key);

        // Revoke previous tokens if you want to enforce single‑session (optional)
        // $user->tokens()->delete();

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'user'    => $user->only(['id', 'name', 'email', 'role']),
            'token'   => $token,
        ]);
    }

    /** 10 wrong attempts in a row lock the account for 15 minutes. */
    private function registerFailure(?User $user): void
    {
        if (!$user) {
            return;
        }
        $failed = $user->failed_logins + 1;
        $user->forceFill([
            'failed_logins' => $failed >= 10 ? 0 : $failed,
            'locked_until' => $failed >= 10 ? now()->addMinutes(15) : $user->locked_until,
        ])->save();
    }

    /**
     * Logout user (revoke token).
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Get the authenticated user's profile.
     */
    public function me(Request $request)
    {
        $user = $request->user()->load(['restaurant', 'riderProfile', 'addresses']);
        return response()->json($user);
    }

    /**
     * Refresh token – issue a new one (optional).
     */
    public function refresh(Request $request)
    {
        // Delete the current token
        $request->user()->currentAccessToken()->delete();
        // Create a new token
        $newToken = $request->user()->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Token refreshed',
            'token'   => $newToken,
        ]);
    }

    /**
     * (Optional) Change password – with current password verification.
     */
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect',
            ], 422);
        }

        $user->password = Hash::make($request->new_password);
        $user->save();

        return response()->json([
            'message' => 'Password changed successfully',
        ]);
    }
}
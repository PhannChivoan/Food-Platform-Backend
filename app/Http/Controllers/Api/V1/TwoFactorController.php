<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PlatformSettings;
use App\Services\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;

/** Optional two-factor sign-in (authenticator app) for the signed-in account. */
class TwoFactorController extends Controller
{
    public function status(Request $request)
    {
        return response()->json(['enabled' => (bool) $request->user()->two_factor_enabled]);
    }

    /** Step 1: create a secret and show it (as text and as an otpauth link) - not active yet. */
    public function setup(Request $request)
    {
        $user = $request->user();
        abort_if($user->two_factor_enabled, 422, 'Two-factor sign-in is already on.');

        $secret = TwoFactor::newSecret();
        $user->forceFill(['two_factor_secret' => Crypt::encryptString($secret)])->save();

        return response()->json([
            'secret' => $secret,
            'uri' => TwoFactor::uri($secret, $user->email, PlatformSettings::get('platform_name', 'FoodOrder') ?: 'FoodOrder'),
        ]);
    }

    /** Step 2: prove the app works by typing a code, then it is switched on. */
    public function enable(Request $request)
    {
        $v = $request->validate(['code' => 'required|string']);
        $user = $request->user();
        abort_unless($user->two_factor_secret, 422, 'Start the setup first.');

        $secret = Crypt::decryptString($user->two_factor_secret);
        abort_unless(TwoFactor::verify($secret, $v['code']), 422, 'That code is not correct. Check the time on your phone and try again.');

        $user->forceFill(['two_factor_enabled' => true])->save();

        return response()->json(['message' => 'Two-factor sign-in is on.', 'enabled' => true]);
    }

    public function disable(Request $request)
    {
        $v = $request->validate(['password' => 'required|string', 'code' => 'required|string']);
        $user = $request->user();

        abort_unless(Hash::check($v['password'], $user->password), 422, 'Password is incorrect.');
        abort_unless(
            $user->two_factor_secret && TwoFactor::verify(Crypt::decryptString($user->two_factor_secret), $v['code']),
            422,
            'That code is not correct.'
        );

        $user->forceFill(['two_factor_enabled' => false, 'two_factor_secret' => null])->save();

        return response()->json(['message' => 'Two-factor sign-in is off.', 'enabled' => false]);
    }
}

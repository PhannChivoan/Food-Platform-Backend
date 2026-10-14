<?php

namespace App\Http\Middleware;

use App\Services\PlatformSettings;
use Closure;
use Illuminate\Http\Request;

/*
 * Applies the timezone chosen in /admin/settings for the current request, so every
 * date the API returns is in the platform's timezone instead of the server default.
 *
 * Access windows are matched by PlatformSettings::now(), which is timezone-aware on
 * its own, so an old value left in config/app.php cannot shift a window boundary.
 */
class ApplyPlatformTimezone
{
    public function handle(Request $request, Closure $next)
    {
        config(['app.timezone' => PlatformSettings::timezone()]);

        return $next($request);
    }
}
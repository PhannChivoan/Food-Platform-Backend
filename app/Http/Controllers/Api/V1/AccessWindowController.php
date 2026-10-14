<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AccessWindow;
use App\Services\AccessWindowService;
use Illuminate\Http\Request;

/**
 * Access windows restrict a role to certain days/hours. Each window means "this role
 * MAY work during this window"; outside every one of its windows the role is locked
 * out of the operational modules (see EnforceAccessWindow).
 */
class AccessWindowController extends Controller
{
    public function index()
    {
        $now = \App\Services\PlatformSettings::now();

        $windows = AccessWindow::orderBy('start_time')->get()->map(function (AccessWindow $w) use ($now) {
            return array_merge($w->toArray(), [
                'days' => is_array($w->days) ? $w->days : [],
                'schedule' => AccessWindowService::describe($w),
                'is_open' => (bool) $w->is_active && AccessWindowService::windowContains($w, $now),
            ]);
        });

        return response()->json($windows);
    }

    /** What the signed-in role may do right now. */
    public function status(Request $request)
    {
        return response()->json(AccessWindowService::status($request->user()?->role));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'label' => 'required|string|max:255',
            'start_time' => 'required',
            'end_time' => 'required',
            'days' => 'required|array|min:1',
            'days.*' => 'string|in:Mon,Tue,Wed,Thu,Fri,Sat,Sun',
            'applies_to' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $window = AccessWindow::create($validated);
        return response()->json($window, 201);
    }

    public function show(AccessWindow $accessWindow)
    {
        return response()->json($accessWindow);
    }

    public function update(Request $request, AccessWindow $accessWindow)
    {
        $validated = $request->validate([
            'label' => 'sometimes|required|string|max:255',
            'start_time' => 'sometimes|required',
            'end_time' => 'sometimes|required',
            'days' => 'sometimes|required|array|min:1',
            'days.*' => 'string|in:Mon,Tue,Wed,Thu,Fri,Sat,Sun',
            'applies_to' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $accessWindow->update($validated);
        return response()->json($accessWindow);
    }

    public function destroy(AccessWindow $accessWindow)
    {
        $accessWindow->delete();
        return response()->json(['message' => 'Access window deleted.']);
    }

    public function toggle(AccessWindow $accessWindow)
    {
        $accessWindow->update(['is_active' => !$accessWindow->is_active]);
        return response()->json($accessWindow);
    }
}

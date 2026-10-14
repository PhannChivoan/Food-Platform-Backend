<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AccessWindow;
use Illuminate\Http\Request;

class AccessWindowController extends Controller
{
    public function index()
    {
        $windows = AccessWindow::orderBy('start_time')->get();
        return response()->json($windows);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'label' => 'required|string|max:255',
            'start_time' => 'required',
            'end_time' => 'required',
            'days' => 'required|array',
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
            'days' => 'sometimes|required|array',
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

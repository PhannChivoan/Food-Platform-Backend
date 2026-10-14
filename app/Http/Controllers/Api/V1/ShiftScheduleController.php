<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ShiftSchedule;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShiftScheduleController extends Controller
{
    public function index(Request $request)
    {
        $perPage = max(1, (int) $request->get('per_page', 20));
        $query = ShiftSchedule::query();

        if ($request->filled('role')) {
            $query->where('role', $request->get('role'));
        }

        if ($request->filled('start_date')) {
            $query->where('start_time', '>=', $request->get('start_date'));
        }

        if ($request->filled('end_date')) {
            $query->where('end_time', '<=', $request->get('end_date'));
        }

        return response()->json([
            'data' => $query->orderBy('start_time', 'desc')->paginate($perPage),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'role' => 'required|string|max:255',
            'name' => 'nullable|string|max:255',
            'start_time' => 'required|date',
            'end_time' => 'required|date|after:start_time',
            'days' => 'nullable|array',
            'days.*' => 'string|in:Mon,Tue,Wed,Thu,Fri,Sat,Sun',
            'is_active' => 'nullable|boolean',
        ]);

        $schedule = ShiftSchedule::create($validated);

        return response()->json([
            'message' => 'Shift schedule created.',
            'data' => $schedule,
        ], 201);
    }

    public function show($id)
    {
        $schedule = ShiftSchedule::find($id);

        if (!$schedule) {
            return response()->json([
                'message' => 'Shift schedule not found.',
            ], 404);
        }

        return response()->json(['data' => $schedule]);
    }

    public function update(Request $request, $id)
    {
        $schedule = ShiftSchedule::find($id);

        if (!$schedule) {
            return response()->json([
                'message' => 'Shift schedule not found.',
            ], 404);
        }

        $validated = $request->validate([
            'role' => 'nullable|string|max:255',
            'name' => 'nullable|string|max:255',
            'start_time' => 'nullable|date',
            'end_time' => 'nullable|date|after:start_time',
            'days' => 'nullable|array',
            'days.*' => 'string|in:Mon,Tue,Wed,Thu,Fri,Sat,Sun',
            'is_active' => 'nullable|boolean',
        ]);

        $schedule->update($validated);

        return response()->json([
            'message' => 'Shift schedule updated.',
            'data' => $schedule->fresh(),
        ]);
    }

    public function destroy($id)
    {
        $schedule = ShiftSchedule::find($id);

        if (!$schedule) {
            return response()->json([
                'message' => 'Shift schedule not found.',
            ], 404);
        }

        $schedule->delete();

        return response()->json([
            'message' => 'Shift schedule deleted.',
        ]);
    }
}

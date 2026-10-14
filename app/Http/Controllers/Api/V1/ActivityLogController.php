<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $query = ActivityLog::with('user');
        if ($request->filled('role')) {
            $query->whereHas('user', fn($q) => $q->where('role', $request->role));
        }
        if ($request->filled('event')) {
            $query->where('event', $request->event);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        $logs = $query->orderBy('created_at', 'desc')->paginate(20);
        return response()->json($logs);
    }

    public function filters()
    {
        $roles = \App\Models\User::distinct()->pluck('role');
        $events = ActivityLog::distinct()->pluck('event');
        return response()->json(['roles' => $roles, 'events' => $events]);
    }

    public function export(Request $request)
    {
        // For simplicity, return CSV download – you can implement using a package like 'laravel-excel'
        return response()->json(['message' => 'Export functionality – use a library like Maatwebsite/Excel']);
    }
}
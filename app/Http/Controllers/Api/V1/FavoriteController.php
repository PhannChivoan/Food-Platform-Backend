<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** A customer's favourite restaurants. */
class FavoriteController extends Controller
{
    public function index(Request $request)
    {
        $rows = DB::table('favorites')
            ->join('restaurants', 'restaurants.id', '=', 'favorites.restaurant_id')
            ->where('favorites.user_id', $request->user()->id)
            ->whereNull('restaurants.deleted_at')
            ->orderByDesc('favorites.created_at')
            ->get(['restaurants.id', 'restaurants.name', 'restaurants.cuisine_type', 'restaurants.logo']);

        return response()->json([
            'ids' => $rows->pluck('id'),
            'data' => $rows->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'cuisine_type' => $r->cuisine_type,
                'logo_url' => $r->logo ? asset('storage/' . $r->logo) : null,
            ]),
        ]);
    }

    public function store(Request $request, int $restaurantId)
    {
        abort_unless(DB::table('restaurants')->where('id', $restaurantId)->whereNull('deleted_at')->exists(), 404, 'Restaurant not found');

        DB::table('favorites')->updateOrInsert(
            ['user_id' => $request->user()->id, 'restaurant_id' => $restaurantId],
            ['created_at' => now(), 'updated_at' => now()],
        );

        return response()->json(['favorite' => true]);
    }

    public function destroy(Request $request, int $restaurantId)
    {
        DB::table('favorites')->where('user_id', $request->user()->id)->where('restaurant_id', $restaurantId)->delete();

        return response()->json(['favorite' => false]);
    }
}

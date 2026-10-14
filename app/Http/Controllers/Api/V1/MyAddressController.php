<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** A signed-in customer's saved delivery addresses (with the pinned map location). */
class MyAddressController extends Controller
{
    private function list(int $userId)
    {
        return DB::table('customer_addresses')->where('user_id', $userId)->where('is_hidden', false)
            ->selectRaw('id, label, address, details, city, postal_code, is_default, ST_Y(location) as latitude, ST_X(location) as longitude')
            ->orderByDesc('is_default')->orderByDesc('id')->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'label' => $a->label,
                'address' => $a->address,
                'details' => $a->details,
                'city' => $a->city,
                'postal_code' => $a->postal_code,
                'is_default' => (bool) $a->is_default,
                'latitude' => $a->latitude !== null ? (float) $a->latitude : null,
                'longitude' => $a->longitude !== null ? (float) $a->longitude : null,
            ])->values();
    }

    public function index(Request $request)
    {
        return response()->json(['data' => $this->list($request->user()->id)]);
    }

    public function store(Request $request)
    {
        $v = $request->validate([
            'label' => 'nullable|string|max:50',
            'address' => 'required|string|max:255',
            'details' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'is_default' => 'nullable|boolean',
        ]);
        $uid = $request->user()->id;
        $lat = (float) $v['latitude'];
        $lng = (float) $v['longitude'];
        $first = !DB::table('customer_addresses')->where('user_id', $uid)->where('is_hidden', false)->exists();

        $id = DB::transaction(function () use ($v, $uid, $lat, $lng, $first) {
            $makeDefault = $first || !empty($v['is_default']);
            if ($makeDefault) {
                DB::table('customer_addresses')->where('user_id', $uid)->update(['is_default' => false]);
            }
            return DB::table('customer_addresses')->insertGetId([
                'user_id' => $uid,
                'label' => $v['label'] ?? 'Other',
                'address' => $v['address'],
                'details' => $v['details'] ?? null,
                'city' => $v['city'] ?? '',
                'postal_code' => $v['postal_code'] ?? null,
                'location' => DB::raw("ST_GeomFromText('POINT({$lng} {$lat})')"),
                'is_default' => $makeDefault,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return response()->json(['id' => $id, 'data' => $this->list($uid)], 201);
    }

    /** Edit a saved address (text, floor / house number, map pin). Past orders keep their own snapshot. */
    public function update(Request $request, int $id)
    {
        $v = $request->validate([
            'label' => 'nullable|string|max:50',
            'address' => 'required|string|max:255',
            'details' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);
        $uid = $request->user()->id;
        $row = DB::table('customer_addresses')->where('id', $id)->where('user_id', $uid)->where('is_hidden', false)->first();
        abort_unless($row, 404, 'Address not found');

        $lat = (float) $v['latitude'];
        $lng = (float) $v['longitude'];
        // Orders already placed with this address must not change under the rider's feet:
        // if it was used, save the edit as a new row and hide the old one.
        if (DB::table('orders')->where('delivery_address_id', $id)->exists()) {
            $new = DB::table('customer_addresses')->insertGetId([
                'user_id' => $uid, 'is_default' => $row->is_default, 'created_at' => now(), 'updated_at' => now(),
                'label' => $v['label'] ?? $row->label, 'address' => $v['address'], 'details' => array_key_exists('details', $v) ? $v['details'] : $row->details,
                'city' => $v['city'] ?? '', 'postal_code' => $v['postal_code'] ?? null,
                'location' => DB::raw("ST_GeomFromText('POINT({$lng} {$lat})')"),
            ]);
            DB::table('customer_addresses')->where('id', $id)->update(['is_hidden' => true, 'is_default' => false]);
            return response()->json(['id' => $new, 'data' => $this->list($uid)]);
        }

        DB::table('customer_addresses')->where('id', $id)->update([
            'label' => $v['label'] ?? $row->label,
            'address' => $v['address'],
            'details' => array_key_exists('details', $v) ? $v['details'] : $row->details,
            'city' => $v['city'] ?? '',
            'postal_code' => $v['postal_code'] ?? null,
            'location' => DB::raw("ST_GeomFromText('POINT({$lng} {$lat})')"),
            'updated_at' => now(),
        ]);
        return response()->json(['id' => $id, 'data' => $this->list($uid)]);
    }

    public function setDefault(Request $request, int $id)
    {
        $uid = $request->user()->id;
        abort_unless(DB::table('customer_addresses')->where('id', $id)->where('user_id', $uid)->exists(), 404, 'Address not found');
        DB::table('customer_addresses')->where('user_id', $uid)->update(['is_default' => false]);
        DB::table('customer_addresses')->where('id', $id)->update(['is_default' => true]);
        return response()->json(['data' => $this->list($uid)]);
    }

    public function destroy(Request $request, int $id)
    {
        $uid = $request->user()->id;
        $row = DB::table('customer_addresses')->where('id', $id)->where('user_id', $uid)->where('is_hidden', false)->first();
        abort_unless($row, 404, 'Address not found');

        // Past orders still point at it, so keep the row but stop listing it.
        if (DB::table('orders')->where('delivery_address_id', $id)->exists()) {
            DB::table('customer_addresses')->where('id', $id)->update(['is_hidden' => true, 'is_default' => false]);
        } else {
            DB::table('customer_addresses')->where('id', $id)->delete();
        }
        if ($row->is_default) {
            $next = DB::table('customer_addresses')->where('user_id', $uid)->where('is_hidden', false)->orderByDesc('id')->value('id');
            if ($next) DB::table('customer_addresses')->where('id', $next)->update(['is_default' => true]);
        }
        return response()->json(['data' => $this->list($uid)]);
    }
}
<?php

namespace Database\Seeders;

use App\Models\DeliveryZone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DeliveryZoneSeeder extends Seeder
{
    public function run()
    {
        // Define a simple polygon (approximate area)
        $polygon = "POLYGON((77.5 12.9, 77.6 12.9, 77.6 13.0, 77.5 13.0, 77.5 12.9))";

        DeliveryZone::create([
            'name' => 'Downtown Area',
            'boundary' => DB::raw("ST_GeomFromText('$polygon')"),
            'base_fee' => 5.00,
            'fee_per_km' => 0.50,
            'free_delivery_threshold' => 20.00,
            'surge_pricing_rules' => [
                ['day' => 'friday', 'start' => '18:00', 'end' => '22:00', 'multiplier' => 1.5],
                ['day' => 'saturday', 'start' => '19:00', 'end' => '23:00', 'multiplier' => 1.8],
            ],
        ]);

        DeliveryZone::create([
            'name' => 'Suburban Area',
            'boundary' => DB::raw("ST_GeomFromText('POLYGON((77.4 12.7, 77.5 12.7, 77.5 12.8, 77.4 12.8, 77.4 12.7))')"),
            'base_fee' => 7.00,
            'fee_per_km' => 0.75,
            'free_delivery_threshold' => 30.00,
            'surge_pricing_rules' => [],
        ]);
    }
}
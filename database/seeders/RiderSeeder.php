<?php

namespace Database\Seeders;

use App\Models\RiderProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RiderSeeder extends Seeder
{
    public function run()
    {
        $riders = User::role('rider')->get();

        foreach ($riders as $rider) {
            RiderProfile::create([
                'user_id' => $rider->id,
                'vehicle_type' => fake()->randomElement(['bike', 'car', 'scooter']),
                'license_plate' => strtoupper(fake()->bothify('???-####')),
                'current_location' => DB::raw("ST_GeomFromText('POINT(" . fake()->longitude . " " . fake()->latitude . ")')"),
                'is_online' => true,
                'is_available' => true,
                'rating' => rand(40, 50) / 10,
                'total_trips' => rand(10, 100),
                'total_earnings' => rand(1000, 5000),
                'documents' => ['license' => 'license.jpg', 'id_card' => 'id.jpg'],
            ]);
        }
    }
}